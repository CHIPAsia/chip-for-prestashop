{*
 * CHIP for PrestaShop - configuration form (rendered by getContent()).
 * Plain HTML form - avoids HelperForm template resolution issues on
 * PrestaShop 9.x while staying compatible with 1.7.x.
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-credit-card"></i> {l s='CHIP Settings' mod='chip'}
  </div>
  <div class="panel-body">
    {if $chip_config_saved}
      <div class="alert alert-success">
        {l s='Settings updated' mod='chip'}
      </div>
    {/if}
    {if $chip_config_error}
      <div class="alert alert-danger">
        {$chip_config_error|escape:'html':'UTF-8'}
      </div>
    {/if}
    <form method="post" action="{$chip_config_action|escape:'html':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-3" for="chip_secret_key">
          {l s='Secret Key' mod='chip'}
        </label>
        <div class="col-lg-9">
          <input type="text" name="CHIP_SECRET_KEY" id="chip_secret_key"
                 class="form-control" value="{$chip_secret_key|escape:'html':'UTF-8'}" required />
          <p class="help-block">{l s='Your CHIP secret key (per brand). Never share this key.' mod='chip'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="chip_brand_id">
          {l s='Brand ID' mod='chip'}
        </label>
        <div class="col-lg-9">
          <input type="text" name="CHIP_BRAND_ID" id="chip_brand_id"
                 class="form-control" value="{$chip_brand_id|escape:'html':'UTF-8'}" required />
          <p class="help-block">{l s='Your CHIP brand ID.' mod='chip'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="chip_payment_methods">
          {l s='Payment Methods' mod='chip'}
        </label>
        <div class="col-lg-9">
          <select name="CHIP_PAYMENT_METHOD_WHITELIST[]" id="chip_payment_methods"
                  class="form-control" multiple size="10">
            {foreach $chip_method_options as $code => $label}
              <option value="{$code|escape:'html':'UTF-8'}"
                {if in_array($code, $chip_method_selected)}selected="selected"{/if}>
                {$label|escape:'html':'UTF-8'}
              </option>
            {/foreach}
          </select>
          <p class="help-block">{l s='Leave empty to allow all payment methods. Select only the methods you want to offer.' mod='chip'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="chip_due_strict">
          {l s='Due Strict' mod='chip'}
        </label>
        <div class="col-lg-9">
          <span class="switch prestashop-switch fixed-width-lg">
            <input type="radio" name="CHIP_DUE_STRICT" id="chip_due_strict_on" value="1"
              {if $chip_due_strict}checked="checked"{/if} />
            <label for="chip_due_strict_on">{l s='Yes' mod='chip'}</label>
            <input type="radio" name="CHIP_DUE_STRICT" id="chip_due_strict_off" value="0"
              {if !$chip_due_strict}checked="checked"{/if} />
            <label for="chip_due_strict_off">{l s='No' mod='chip'}</label>
            <a class="slide-button btn"></a>
          </span>
          <p class="help-block">{l s='When enabled, payment must be completed before the due time.' mod='chip'}</p>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3" for="chip_timezone">
          {l s='Purchase Timezone' mod='chip'}
        </label>
        <div class="col-lg-9">
          <input type="text" name="CHIP_PURCHASE_TIME_ZONE" id="chip_timezone"
                 class="form-control" value="{$chip_timezone|escape:'html':'UTF-8'}" required />
          <p class="help-block">{l s='Timezone used for the purchase (e.g. Asia/Kuala_Lumpur).' mod='chip'}</p>
        </div>
      </div>
      <div class="panel-footer">
        <button type="submit" name="submitChipConfig" class="btn btn-primary">
          <i class="icon-save"></i> {l s='Save' mod='chip'}
        </button>
      </div>
    </form>
  </div>
</div>
