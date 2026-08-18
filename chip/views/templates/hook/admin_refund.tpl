{*
 * CHIP for PrestaShop - admin refund button (displayAdminOrderSide /
 * displayAdminOrderMain / displayAdminOrderContentOrder).
 * Posts to the ChipRefund admin controller (ajax=1&action=refund).
 *}
<div class="panel" id="chip-admin-refund">
  <div class="panel-heading">
    <i class="icon-credit-card"></i> {l s='CHIP Payment' mod='chip'}
  </div>
  <div class="panel-body">
    <p>
      {l s='Purchase ID:' mod='chip'} <code>{$chip_purchase_id|escape:'html':'UTF-8'}</code><br />
      {l s='Refund the full amount paid (RM %s) from the CHIP gateway.' sprintf=[$chip_total_paid] mod='chip'}
    </p>
    <button type="button" class="btn btn-warning" id="chip-refund-button"
      data-refund-url="{$chip_refund_url|escape:'html':'UTF-8'}"
      data-id-order="{$chip_id_order|intval}">
      <i class="icon-undo"></i> {l s='Refund via CHIP' mod='chip'}
    </button>
    <div id="chip-refund-result" style="margin-top:10px;"></div>
  </div>
</div>
<script type="text/javascript">
(function () {
  var button = document.getElementById('chip-refund-button');
  if (!button) {
    return;
  }
  var resultBox = document.getElementById('chip-refund-result');
  button.addEventListener('click', function () {
    if (!window.confirm('{l s='Refund this order via CHIP?' mod='chip' js=1}')) {
      return;
    }
    button.disabled = true;
    resultBox.innerHTML = '<span class="text-muted">{l s='Processing...' mod='chip' js=1}</span>';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', button.getAttribute('data-refund-url'), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }
      var message = 'Error';
      try {
        var data = JSON.parse(xhr.responseText);
        message = data.message || message;
      } catch (e) {
        message = xhr.responseText || message;
      }
      resultBox.innerHTML = '<span class="' + (xhr.status === 200 ? 'text-success' : 'text-danger') + '">' + message + '</span>';
      button.disabled = false;
    };
    xhr.send('ajax=1&action=refund&id_order=' + button.getAttribute('data-id-order'));
  });
})();
</script>
