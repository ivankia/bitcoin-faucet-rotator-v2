<div class="table-responsive">
<table class="table table-striped bordered tablesorter" id="faucets-table">
    <thead>
        <th>Name</th>
        <th>Url</th>
        <th>Interval Minutes</th>
        <th>Min Payout</th>
        <th>Max Payout</th>
        <th>Has Ref Program</th>
        <th>Ref Payout Percent</th>
        <th>Payment Processors</th>
        <th>Is Paused</th>
        <th>Slug</th>
        <th>Has Low Balance</th>
        <th>Action</th>
    </thead>
    <tbody>
    @foreach($faucets as $faucet)
        <tr>
            <td>{!! $faucet->name !!}</td>
            <td>{!! $faucet->url !!}</td>
            <td>{!! $faucet->interval_minutes !!}</td>
            <td>{!! $faucet->min_payout !!}</td>
            <td>{!! $faucet->max_payout !!}</td>
            <td>{!! $faucet->has_ref_program == true ? "Yes" : "No" !!}</td>
            <td>{!! $faucet->ref_payout_percent !!}</td>
            <td>
                <ul>
                @foreach($faucet->paymentProcessors as $p)
                    <li>{{ $p->name }}</li>
                @endforeach
                </ul>
            </td>
            <td>{!! $faucet->is_paused == true ? "Yes" : "No" !!}</td>
            <td>{!! $faucet->slug !!}</td>
            <td>{!! $faucet->has_low_balance == true ? "Yes" : "No" !!}</td>
            <td>

                <div class='btn-group'>
                    <a href="{!! route('faucets.show', [$faucet->slug]) !!}" class='btn btn-default btn-xs'><i class="glyphicon glyphicon-eye-open"></i></a>
                    @if(Auth::user() != null)
                        @if(Auth::user()->is_admin == true)
                            <a href="{!! route('faucets.edit', [$faucet->slug]) !!}" class='btn btn-default btn-xs'><i class="glyphicon glyphicon-edit"></i></a>
                            {!! Form::open(['route' => ['faucets.destroy', $faucet->slug], 'method' => 'delete']) !!}
                            {!! Form::button('<i class="glyphicon glyphicon-trash"></i>', ['type' => 'submit', 'class' => 'btn btn-danger btn-xs', 'onclick' => "return confirm('Are you sure?')"]) !!}
                            {!! Form::close() !!}
                        @endif
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>