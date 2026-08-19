{*
 * CHIP for PrestaShop - "Test API" button on the module configuration page.
 * Posts to the ChipRefund admin controller (ajax=1&action=testapi), which
 * calls GET /payment_methods/ with amount=1000.
 *}
<hr />
<div class="panel">
  <div class="panel-heading"><i class="icon-plug"></i> {l s='CHIP API Test' mod='chip'}</div>
  <div class="panel-body">
    <p>{l s='Verify your Secret Key and Brand ID by querying the CHIP payment methods API.' mod='chip'}</p>
    <button type="button" class="btn btn-default" id="chip-test-api-button"
      data-test-url="{$chip_test_url|escape:'html':'UTF-8'}">
      <i class="icon-refresh"></i> {l s='Test API' mod='chip'}
    </button>
    <div id="chip-test-api-result" style="margin-top:10px;"></div>
  </div>
</div>
<script type="text/javascript">
(function () {
  var button = document.getElementById('chip-test-api-button');
  if (!button) {
    return;
  }
  var resultBox = document.getElementById('chip-test-api-result');
  button.addEventListener('click', function () {
    button.disabled = true;
    resultBox.innerHTML = '<span class="text-muted">{l s='Testing...' mod='chip' js=1}</span>';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', button.getAttribute('data-test-url'), true);
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
    xhr.send('ajax=1&action=testapi');
  });
})();
</script>
