<div class="row">
  <div class="col-xs-12 col-md-6">
    <p class="payment_module">
      {if (version_compare(Configuration::get('PS_INSTALL_VERSION'), '1.5') == -1)}
        <a class="bankwire" href="{$base_dir|cat:'modules/veritranspay/payment.php'}" title="Pay Via Veritrans">
      {else}
        <a class="bankwire" href="{$link->getModuleLink('veritranspay', 'payment')}" title="Pay Via Veritrans">
      {/if}
        <img src="{$this_path}veritrans.jpg" alt="{l s='Pay using Veritrans' mod='veritranspay'}" height="30px"/>
        {l s=$title_btn mod='veritranspay'}
      </a>
    </p>  
  </div>
</div>
<!-- added new discount button -->
{if $discount_btn_enabled}
<div class="row">
  <div class="col-xs-12 col-md-6">
    <p class="payment_module">
      {if (version_compare(Configuration::get('PS_INSTALL_VERSION'), '1.5') == -1)}
        <a class="bankwire" href="{$base_dir|cat:'modules/veritranspay/payment.php'}?is_discount=1" title="Pay Via Veritrans Discount">
      {else}
        <a class="bankwire" href="{$link->getModuleLink('veritranspay', 'payment')}?is_discount=1" title="Pay Via Veritrans Discount">
      {/if}
        <img src="{$this_path}veritrans.jpg" alt="{l s='Pay using Veritrans' mod='veritranspay'}" height="30px"/>
        {l s=$title_btn2 mod='veritranspay'}
      </a>
    </p>  
  </div>
</div>
{/if}