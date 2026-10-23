<?php
if(!isset($fulfilment,$data,$canCreatePayout)){http_response_code(404);exit;}
$activity=null;$activityError='';
if(($_POST['action']??'')==='check_stripe_activity'){
    $activity=stripe_api_request($stripeConfig,'GET','/v1/balance_transactions',['limit'=>100]);
    if(empty($activity['ok']))$activityError=$activity['error']??'Stripe activity is unavailable.';
}
$knownSources=[];
foreach($data['ledger']as$tx){$meta=finance_month_metadata($tx);foreach(['stripe_charge_id','stripe_balance_transaction_id','stripe_payout_id','stripe_refund_id']as$key)if(!empty($meta[$key]))$knownSources[(string)$meta[$key]]=true;if(!empty($tx['reference']))$knownSources[(string)$tx['reference']]=true;}
?>
<section class="card-soft p-3">
<h6>Receipts needing attention</h6>
<div class="text-muted small mb-3">Unallocated receipts and missing fees are flagged here. Reconcile the underlying record before collecting; receipts are never assigned by guessing.</div>
<div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Transaction</th><th>Date</th><th>Reference</th><th>Reason</th><th class="text-end">Unallocated amount</th><th>Action</th></tr></thead><tbody>
<?php foreach($fulfilment['issues']as$issue):$tx=$issue['transaction'];$meta=finance_month_metadata($tx);?>
<tr><td>T-<?php echo (int)$tx['id'];?></td><td><?php echo h(format_display_datetime($tx['created_at'],''));?></td><td><?php echo h($tx['reference']??'');?></td><td><?php echo h($issue['reason']);?></td><td class="text-end"><?php echo $issue['amount']?format_price($issue['amount']/100):'—';?></td><td>
<?php if($canCreatePayout&&str_contains($issue['reason'],'fee missing')&&!empty($meta['stripe_payment_intent'])):?><form method="post"><input type="hidden" name="action" value="reconcile_receipt_fee"><input type="hidden" name="transaction_id" value="<?php echo (int)$tx['id'];?>"><?php echo csrf_input();?><button class="btn btn-sm btn-outline-success">Retrieve Stripe fee</button></form><?php endif;?>
</td></tr><?php endforeach;?>
<?php if(!$fulfilment['issues']):?><tr><td colspan="6" class="text-muted">No unallocated receipts or missing fees were found in the local ledger.</td></tr><?php endif;?>
</tbody></table></div>
</section>
<section class="card-soft p-3 mt-3"><h6>Stripe activity check</h6><p class="text-muted small">Compare recent Stripe activity with local references. This read-only check includes refunds, disputes, adjustments and payouts made directly through Stripe. It does not allocate or collect funds.</p>
<form method="post"><?php echo csrf_input();?><input type="hidden" name="action" value="check_stripe_activity"><button class="btn btn-sm btn-outline-success">Check recent Stripe activity</button></form>
<?php if($activityError!==''):?><div class="alert alert-warning mt-3"><?php echo h($activityError);?></div><?php endif;?>
<?php if(!empty($activity['ok'])):?><p class="small text-muted mt-3">Showing the latest <?php echo count($activity['data']['data']??[]);?> balance transactions. <?php echo !empty($activity['data']['has_more'])?'Earlier activity exists; this is not a full account reconciliation.':'This is an activity comparison, not a full account reconciliation.';?></p>
<div class="table-responsive"><table class="table table-sm align-middle"><thead class="table-light"><tr><th>Date</th><th>Type</th><th>Source / Stripe reference</th><th class="text-end">Amount</th><th class="text-end">Fee</th><th class="text-end">Net</th><th>Local match</th></tr></thead><tbody>
<?php foreach($activity['data']['data']??[]as$entry):$source=is_array($entry['source']??null)?(string)($entry['source']['id']??''):(string)($entry['source']??'');$matched=isset($knownSources[$source])||isset($knownSources[(string)$entry['id']]);?>
<tr><td><?php echo h(date('d M Y H:i',(int)$entry['created']));?></td><td><?php echo h($entry['type']);?></td><td><?php echo h($source);?><div class="small text-muted"><?php echo h($entry['id']);?></div></td><td class="text-end"><?php echo h(strtoupper((string)$entry['currency']).' '.number_format((int)$entry['amount']/100,2));?></td><td class="text-end"><?php echo number_format((int)$entry['fee']/100,2);?></td><td class="text-end"><?php echo number_format((int)$entry['net']/100,2);?></td><td class="<?php echo $matched?'text-success':'text-warning';?>"><?php echo $matched?'Reference found':'Review — no local reference';?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section>
