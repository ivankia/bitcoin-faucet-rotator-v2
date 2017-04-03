<?php namespace App\Helpers\Functions;

use App\Http\Requests\CreateFaucetRequest;
use App\Http\Requests\UpdateFaucetRequest;
use Laracasts\Flash\Flash as LaracastsFlash;
use App\Models\PaymentProcessor;
use App\Repositories\FaucetRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Class Faucets
 *
 * A helper class to handle extra funtionality
 * related to currently stored faucets.
 *
 * @author Rob Attfield <emailme@robertattfield.com> <http://www.robertattfield.com>
 * @package App\Helpers\Functions
 */
class Faucets
{
    private $faucetRepository;
    public function __construct(FaucetRepository $faucetRepository)
    {
        $this->faucetRepository = $faucetRepository;
    }

    /**
     * Create and store a new faucet.
     * @param CreateFaucetRequest $request
     */
    public function createStoreFaucet(CreateFaucetRequest $request){

        $input = $request->except('payment_processors', 'slug', 'referral_code');

        $faucet = $this->faucetRepository->create($input);

        $paymentProcessors = $request->get('payment_processors');
        $referralCode = $request->get('referral_code');

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        $faucet->first()->paymentProcessors->detach();

        if(count($paymentProcessors) >= 1){
            foreach ($paymentProcessors as $paymentProcessorId) {
                $faucet->first()->paymentProcessors->attach((int)$paymentProcessorId);
            }
        }

        if(Auth::user()->hasRole('owner')){
            Auth::user()->faucets()->sync([$faucet->id => ['referral_code' => $referralCode]]);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Update the specified faucet.
     * @param $slug
     * @param UpdateFaucetRequest $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function updateFaucet($slug, UpdateFaucetRequest $request){

        $currentFaucet = $this->faucetRepository->findByField('slug', $slug, true)->first();

        $faucet = $this->faucetRepository->update($request->all(), $currentFaucet->id);

        $paymentProcessors = $request->get('payment_processors');
        $paymentProcessorIds = $request->get('payment_processors');

        $referralCode = $request->get('referral_code');

        if(count($paymentProcessorIds) == 1){
            $paymentProcessors = PaymentProcessor::where('id', $paymentProcessorIds[0]);
        }
        else if(count($paymentProcessorIds) >= 1){
            $paymentProcessors = PaymentProcessor::whereIn('id', $paymentProcessorIds);
        }

        if (empty($faucet)) {
            LaracastsFlash::error('Faucet not found');

            return redirect(route('faucets.index'));
        }

        $toAddPaymentProcressorIds = [];

        foreach($paymentProcessors->pluck('id')->toArray() as $key => $value){
            array_push($toAddPaymentProcressorIds, (int)$value);
        }

        if(count($toAddPaymentProcressorIds) > 1){
            $faucet->paymentProcessors()->sync($toAddPaymentProcressorIds);
        }
        else if(count($toAddPaymentProcressorIds) == 1){
            $faucet->paymentProcessors()->sync([$toAddPaymentProcressorIds[0]]);
        }

        if(Auth::user()->hasRole('owner')){
            $faucet->users()->sync([Auth::user()->id => ['faucet_id' => $faucet->id, 'referral_code' => $referralCode]]);
        }
    }

    /**
     * Soft-delete or permanently delete a faucet.
     * @param $slug
     * @param bool $permanentlyDelete
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function destroyFaucet($slug, bool $permanentlyDelete = false){

        $faucet = $this->faucetRepository->findByField('slug', $slug)->first();

        if (empty($faucet)) {
            LaracastsFlash::error('Faucet not found');

            return redirect(route('faucets.index'));
        }

        if(!empty($faucet) && $faucet->isDeleted()){
            LaracastsFlash::error('The faucet has already been deleted.');

            return redirect(route('faucets.index'));
        }

        if($permanentlyDelete == false){
            $this->faucetRepository->deleteWhere(['slug' => $slug]);
        } else{
            $this->faucetRepository->deleteWhere(['slug' => $slug], true);
        }

    }

    /**
     * Restore a specified soft-deleted faucet.
     * @param $slug
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function restoreFaucet($slug){
        $faucet = $this->faucetRepository->findByField('slug', $slug)->first();

        if (empty($faucet)) {
            LaracastsFlash::error('Faucet not found');

            return redirect(route('faucets.index'));
        }

        if(!empty($faucet) && !$faucet->isDeleted()){
            LaracastsFlash::error('The faucet has already been restored or is still active.');

            return redirect(route('faucets.index'));
        }

        $this->faucetRepository->restoreDeleted($slug);
    }
}
