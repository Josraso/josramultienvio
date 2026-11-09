{**
 * Envíos múltiples: detalle de cada envío de la opción elegida
 *}
<div id="josramulti" class="josramulti" data-options="{$jme_json|escape:'html':'UTF-8'}">
  {foreach from=$jme_options key=jme_key item=jme_option}
    <div class="josramulti-option alert alert-info" data-key="{$jme_key|escape:'html':'UTF-8'}"{if $jme_key != $jme_selected} style="display:none"{/if}>
      <p class="josramulti-msg"><strong>{$jme_option.message|escape:'html':'UTF-8'}</strong></p>
      <ul class="josramulti-list">
        {foreach from=$jme_option.carriers item=jme_carrier}
          <li>
            <strong>{$jme_carrier.name|escape:'html':'UTF-8'}</strong>{if $jme_carrier.delay} <span class="josramulti-delay">({$jme_carrier.delay|escape:'html':'UTF-8'})</span>{/if}: <span class="josramulti-price">{$jme_carrier.price|escape:'html':'UTF-8'}</span>
            {if $jme_carrier.products}<br><small class="josramulti-products">{$jme_carrier.products|escape:'html':'UTF-8'}</small>{/if}
          </li>
        {/foreach}
      </ul>
    </div>
  {/foreach}
</div>
