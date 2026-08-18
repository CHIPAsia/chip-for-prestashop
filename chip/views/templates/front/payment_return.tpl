{*
 * CHIP for PrestaShop - payment return template.
 * Shown on the order confirmation page after a CHIP payment
 * (hook displayPaymentReturn).
 *}
<section class="chip-payment-return">
  <h4>{$chip_module_name|escape:'html':'UTF-8'}</h4>
  <p>{l s='Thank you for your payment.' mod='chip'}</p>
  <dl>
    <dt>{l s='Order reference' mod='chip'}</dt>
    <dd>{$chip_order_reference|escape:'html':'UTF-8'}</dd>
    <dt>{l s='Amount paid' mod='chip'}</dt>
    <dd>{$chip_total_paid|escape:'html':'UTF-8'}</dd>
    <dt>{l s='Payment method' mod='chip'}</dt>
    <dd>{$chip_payment_method|escape:'html':'UTF-8'}</dd>
  </dl>
  <p>{l s='A payment confirmation has been sent to CHIP. Your order is being processed.' mod='chip'}</p>
</section>
