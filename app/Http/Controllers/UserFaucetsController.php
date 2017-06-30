<?php

namespace App\Http\Controllers;

use App\Helpers\Functions\Faucets;
use App\Http\Requests\CreateUserFaucetRequest;
use App\Http\Requests\UpdateUserFaucetRequest;
use App\Models\Faucet;
use App\Models\PaymentProcessor;
use App\Models\User;
use App\Repositories\UserFaucetRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Mews\Purifier\Facades\Purifier;
use Prettus\Repository\Criteria\RequestCriteria;

class UserFaucetsController extends Controller
{
    /** @var  UserFaucetRepository */
    private $userFaucetRepository;

    private $userRepository;

    private $faucetFunctions;

    /**
     * UserFaucetsController constructor.
     * @param UserFaucetRepository $userFaucetRepo
     * @param UserRepository $userRepository
     * @param Faucets $faucetFunctions
     */
    public function __construct(
        UserFaucetRepository $userFaucetRepo,
        UserRepository $userRepository,
        Faucets $faucetFunctions
    ) {
        $this->userFaucetRepository = $userFaucetRepo;
        $this->userRepository = $userRepository;
        $this->faucetFunctions = $faucetFunctions;
        $this->middleware('auth', ['except' => ['index', 'show']]);
    }


    /**
     * Display a listing of the Faucet.
     *
     * @param $userSlug
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index($userSlug, Request $request)
    {
        $this->userFaucetRepository->pushCriteria(new RequestCriteria($request));
        $user = null;

        $user = User::where('slug', $userSlug)->first();

        // If the assigned user doesn't exist, redirect to users listing instead, with 'not found' flash message.
        if (empty($user)) {
            flash('User not found.')->error();
            return redirect(route('users.index'));
        }

        if ($user->isAnAdmin()) {
            return redirect(route('faucets.index'));
        }

        if (Auth::guest() == true) {
            $showFaucets = $this->faucetFunctions->getUserFaucets($user, false);
        } else {
            $showFaucets = $this->faucetFunctions->getUserFaucets($user, true);
        }

        $paymentProcessors = PaymentProcessor::orderBy('name', 'asc')->pluck('name', 'id');

        return view('users.faucets.index')
            ->with('user', $user)
            ->with('faucets', $showFaucets)
            ->with('paymentProcessors', $paymentProcessors);
    }

    /**
     * Show the form for creating a new Faucet.
     *
     * @param $userSlug
     * @return \Illuminate\View\View
     */
    public function create($userSlug)
    {
        $paymentProcessors = PaymentProcessor::orderBy('name', 'asc')->get();
        $faucetPaymentProcessorIds = null;
        $faucet = null;

        $faucets = Faucet::distinct()->orderBy('name', 'asc')->get();
        $userFaucets = null;

        $user = User::where('slug', $userSlug)->first();

        // If the assigned user doesn't exist, redirect to users listing instead, with 'not found' flash message.
        if (empty($user)) {
            flash('User not found.')->error();
            return redirect(route('users.index'));
        }

        if ($user->isAnAdmin()) {
            return redirect(route('faucets.create'));
        }

        if (Auth::user()->hasRole('user') || Auth::user()->isAnAdmin()) {
            $userFaucets = $this->faucetFunctions->getUserFaucets($user, true);
        } else {
            $userFaucets = $this->faucetFunctions->getUserFaucets($user, false);
        }

        $availableFaucets = $faucets->except($userFaucets->modelKeys());

        return view('users.faucets.create')
            ->with('paymentProcessors', $paymentProcessors)
            ->with('faucetPaymentProcessorIds', $faucetPaymentProcessorIds)
            ->with('faucets', $availableFaucets)
            ->with('user', $user);
    }

    /**
     * Store a newly created Faucet in storage.
     *
     * @param $userSlug
     * @param CreateUserFaucetRequest $request
     * @return Response
     */
    public function store($userSlug, CreateUserFaucetRequest $request)
    {
        $user = $this->userRepository->findByField('slug', $userSlug)->first();

        if (empty($user)) {
            flash('User not found.')->error();
            return redirect(route('users.index'));
        }

        if (Auth::user()->isAnAdmin() || Auth::user() == $user) {
            $input = $request->except('_token', 'payment_processor');

            $redirectRoute = route('users.faucets', $user->slug);

            $this->userFaucetRepository->create($input);

            if (!empty($request->get('payment_processor'))) {
                $input = $request->except('_token');
                $paymentProcessor = PaymentProcessor::where('slug', self::cleanInput($input)['payment_processor'])->first();
            }

            if (!empty($user) && !empty($paymentProcessor)) {
                $redirectRoute = route(
                    'users.payment-processors.faucets',
                    [
                        'userSlug' => $user->slug,
                        'paymentProcessorSlug' => $paymentProcessor->slug
                    ]
                );
            }

            flash('Faucet saved successfully.')->success();

            return redirect($redirectRoute);
        } else {
            return abort(403);
        }
    }

    /**
     * Display the specified Faucet.
     *
     * @param $userSlug
     * @param $faucetSlug
     * @return \Illuminate\View\View
     */
    public function show($userSlug, $faucetSlug)
    {
        $user = null;
        if (Auth::user()->isAnAdmin()) {
            $user = $this->userRepository->findByField('slug', $userSlug, true)->first();
        } else {
            $user = $this->userRepository->findByField('slug', $userSlug)->first();
        }

        //If there is no such user, about to 404 page.
        if (empty($user)) {
            flash('User not found')->error();
            return redirect(route('users.index'));
        }

        $faucet = $user->faucets()->where('slug', '=', $faucetSlug)->first();

        $mainFaucet = Faucet::where('slug', $faucetSlug)->withTrashed()->first();

        $message = null;

        // If the visitor isn't authenticated, the user's faucet is soft-deleted, and main admin faucet exists.
        if (Auth::guest() && ($mainFaucet != null && $faucet->pivot->deleted_at != null)) {
            flash('The faucet was not found')->error();
            return redirect(route('users.faucets', $user->slug));
        }

        // If the main admin faucet exists, and the user's faucet is soft-deleted.
        if ($mainFaucet != null && $faucet->pivot->deleted_at != null) {
            //If the visitor isn't authenticated.
            if (Auth::guest()) {
                flash('The faucet was not found')->error();
                return redirect(route('users.faucets', $user->slug));
            }
            //If the authenticated user is an owner or standard user.
            if ((Auth::user()->isAnAdmin() || Auth::user()->hasRole('user')) || $user === Auth::user()) {
                $message = null;
                if (Auth::user()->isAnAdmin()) {
                    $message = 'The faucet has been temporarily deleted by it\'s user. You can restore the faucet or permanently delete it.';
                } elseif (Auth::user()->hasRole('user')) {
                    $message = 'You have deleted the faucet that was requested; however, you are able to restore this faucet.';
                }

                return redirect(route('users.faucets', $user->slug))->with('message', $message);
            }
        }

        // If the user's faucet exists, and main admin faucet is soft-deleted.
        elseif (!empty($faucet) && $faucet->isDeleted()) {
            //If the authenticated user is an owner or standard user, or the faucet's user is currently authenticated.
            if ((Auth::user()->isAnAdmin() || Auth::user()->hasRole('user')) || $user === Auth::user()) {
                if (Auth::user()->hasRole('user')) {
                    $message = 'The owner of this rotator has deleted the faucet you requested. You can contact them if you would like it to be restored.';
                } elseif (Auth::user()->isAnAdmin()) {
                    $message = 'The faucet has been temporarily deleted by it\'s user. You can restore the faucet or permanently delete it.';
                }

                return view('users.faucets.show')
                    ->with('user', $user)
                    ->with('faucet', $faucet)
                    ->with('message', $message);
            } else {
                flash('The faucet was not found')->error();
                return redirect(route('users.faucets', $user->slug));
            }
        }

        // If user faucet exists, and the user's faucet is soft-deleted.
        elseif (!empty($faucet) && $faucet->pivot->deleted_at != null) {
            if (!Auth::guest() && (Auth::user()->isAnAdmin() || Auth::user()->hasRole('user')) && $user == Auth::user()) {
                if (Auth::user()->hasRole('user')) {
                    $message = 'You have deleted the faucet that was requested; however, you are able to restore this faucet.';
                } elseif (Auth::user()->hasRole('owner')) {
                    $message = 'The faucet has been temporarily deleted by the user. You can restore the faucet or permanently delete it.';
                }

                return view('users.faucets.show')
                    ->with('user', $user)
                    ->with('faucet', $faucet)
                    ->with('message', $message);
            }
        } else {
            //If user faucet exists
            if (!empty($faucet)) {
                return view('users.faucets.show')
                    ->with('user', $user)
                    ->with('faucet', $faucet)
                    ->with('message', $message);
            } else {
                flash('The faucet was not found')->error();
                return redirect(route('users.faucets', $user->slug));
            }
        }
    }

    /**
     * Update the specified Faucet in storage.
     *
     * @param $userSlug
     * @param $faucetSlug
     * @param UpdateUserFaucetRequest $request
     * @return Response
     */
    public function update($userSlug, $faucetSlug, UpdateUserFaucetRequest $request)
    {
        $user = $this->userRepository->findByField('slug', $userSlug, true)->first();

        $input = $request->except('_token', '_method');

        //If there is no such user, about to 404 page.
        if (empty($user) || ($user->isDeleted() && !Auth::user()->isAnAdmin())) {
            flash('User not found')->error();
            return redirect(route('users.index'));
        }

        $faucet = $user->faucets()->where('slug', '=', $faucetSlug)->first();
        $redirectRoute = route('users.faucets', $user->slug);

        if (empty($faucet)) {
            flash('The faucet was not found')->error();

            return redirect(route('users.faucets', $user->slug));
        }

        if (!empty($input['payment_processor'])) {
            $paymentProcessor = PaymentProcessor::where('slug', self::cleanInput($input)['payment_processor'])->first();
        }

        if (!empty($user) && !empty($faucet) && !empty($paymentProcessor)) {
            $redirectRoute = route(
                'users.payment-processors.faucets',
                [
                    'userSlug' => $user->slug,
                    'paymentProcessorSlug' => $paymentProcessor->slug
                ]
            );
        }

        $this->userFaucetRepository->update($input, $user->id);

        flash('The faucet \'' . $faucet->name . '\' was updated successfully!')->success();

        return redirect($redirectRoute);
    }

    /**
     * [paymentProcessorFaucets description]
     * @param  string $userSlug The user's slug.
     * @return Response
     */
    public function paymentProcessors($userSlug)
    {
        $user = User::where('slug', $userSlug)->first();

        $paymentProcessors = PaymentProcessor::all();

        if (empty($user)) {
            abort(404);
        }

        if (empty($paymentProcessors)) {
            flash('No payment processors were found.')->error();
            return redirect(route('users.payment-processors', $user->slug));
        }
    }

    /**
     * Soft-delete the specified Faucet from storage.
     *
     * @param $userSlug
     * @param $faucetSlug
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function destroy($userSlug, $faucetSlug)
    {
        $user = $this->userRepository->findByField('slug', $userSlug, true)->first();

        //If there is no such user, about to 404 page.
        if (empty($user) || ($user->isDeleted() && !Auth::user()->isAnAdmin())) {
            flash('User not found')->error();
            return redirect(route('users.index'));
        }

        $faucet = $user->faucets()->where('slug', '=', $faucetSlug)->first();
        $mainFaucet = Faucet::where('slug', $faucetSlug)->first();

        $redirectRoute = route('users.faucets', $user->slug);

        if (empty($faucet)) {
            flash('Faucet not found.')->error();

            return redirect($redirectRoute);
        }

        if (!empty($faucet) && $mainFaucet->isDeleted()) {
            flash(
                'The owner has temporarily deleted this faucet, 
                and you are not able to restore it. You can ask 
                the owner if the faucet can be restored.'
            )->error();

            return redirect($redirectRoute);
        }

        if (!empty($faucet) && $faucet->pivot->deleted_at != null) {
            flash('The faucet has already been soft-deleted.')->error();

            return redirect($redirectRoute);
        }

        $input = Input::all();
        if (!empty($input['payment_processor'])) {
            $paymentProcessor = PaymentProcessor::where('slug', self::cleanInput($input)['payment_processor'])->first();
        }

        if (!empty($user) && !empty($faucet) && !empty($paymentProcessor)) {
            $redirectRoute = route(
                'users.payment-processors.faucets',
                    [
                        'userSlug' => $user->slug,
                        'paymentProcessorSlug' => $paymentProcessor->slug
                    ]
            );
        }

        $faucetName = $faucet->name;

        DB::table('referral_info')
            ->where('user_id', $user->id)
            ->where('faucet_id', $faucet->id)
            ->update(['deleted_at' => Carbon::now()]);

        flash('The faucet \'' . $faucetName . '\' has successfully been archived/deleted! You are able to restore your faucet.')->success();

        return redirect($redirectRoute);
    }

    /**
     * Permanently delete the specified Faucet from storage.
     *
     * @param $userSlug
     * @param $faucetSlug
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function destroyPermanently($userSlug, $faucetSlug)
    {
        $user = $this->userRepository->findByField('slug', $userSlug, true)->first();

        //If there is no such user, about to 404 page.
        if (empty($user) || ($user->isDeleted() && !Auth::user()->isAnAdmin())) {
            flash('User not found')->error();
            return redirect(route('users.index'));
        }

        $faucet = $user->faucets()->where('slug', '=', $faucetSlug)->first();

        $redirectRoute = route('users.faucets', $user->slug);

        if (empty($faucet)) {
            flash('The faucet was not found, or has already been permanently deleted.')->error();

            return redirect($redirectRoute);
        }

        $faucetName = $faucet->name;

        DB::table('referral_info')
            ->where('user_id', $user->id)
            ->where('faucet_id', $faucet->id)
            ->delete();

        $input = Input::all();
        if (!empty($input['payment_processor'])) {
            $paymentProcessor = PaymentProcessor::where('slug', self::cleanInput($input)['payment_processor'])->first();
        }

        if (!empty($user) && !empty($paymentProcessor)) {
            $redirectRoute = route(
                'users.payment-processors.faucets',
                [
                    'userSlug' => $user->slug,
                    'paymentProcessorSlug' => $paymentProcessor->slug
                ]
            );
        }

        flash('The faucet \'' . $faucetName . '\' was permanently deleted!')->success();

        return redirect($redirectRoute);
    }

    /**
     * @param $userSlug
     * @param $faucetSlug
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function restoreDeleted($userSlug, $faucetSlug)
    {
        $user = $this->userRepository->findByField('slug', $userSlug, true)->first();

        //If there is no such user, about to 404 page.
        if (empty($user) || ($user->isDeleted() && !Auth::user()->isAnAdmin())) {
            flash('User not found')->error();
            return redirect(route('users.index'));
        }

        $faucet = $user->faucets()->where('slug', '=', $faucetSlug)->withTrashed()->first();

        $redirectRoute = route('users.faucets', $user->slug);

        if (empty($faucet)) {
            flash('The faucet was not found, or has been permanently deleted.')->error();

            return redirect(route('users.faucets', $user->slug));
        }

        if (!empty($faucet) && $faucet->isDeleted()) {
            flash(
                'The owner has temporarily deleted this faucet, 
                and you are not able to restore it. You can 
                ask the owner if the faucet can be restored.'
            )->error();

            return redirect($redirectRoute);
        }

        if ($faucet->pivot->deleted_at == null) {
            flash('The faucet is already active, and hasn\'t been deleted.')->error();

            return redirect($redirectRoute);
        }

        $input = Input::all();
        if (!empty($input['payment_processor'])) {
            $paymentProcessor = PaymentProcessor::where('slug', self::cleanInput($input)['payment_processor'])->first();
        }

        if (!empty($user) && !empty($faucet) && !empty($paymentProcessor)) {
            $redirectRoute = route(
                'users.payment-processors.faucets',
                [
                    'userSlug' => $user->slug,
                    'paymentProcessorSlug' => $paymentProcessor->slug
                ]
            );
        }

        $faucetName = $faucet->name;

        DB::table('referral_info')
            ->where('user_id', $user->id)
            ->where('faucet_id', $faucet->id)
            ->update(['deleted_at' => null]);

        flash('The faucet \'' . $faucetName . '\' was successfully restored!')->success();

        return redirect($redirectRoute);
    }

    private static function cleanInput(array $data)
    {
        $data['payment_processor'] = Purifier::clean($data['payment_processor'], 'generalFields');
        return $data;
    }
}
