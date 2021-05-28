{extends file='page.tpl'}
{block name='content'}
    <div class="card">
        <div class="card-block">
            <h1>
                {if empty($status) || $status == 2}
                    {l s='Transaction declined' mod='dpo'}
                {elseif $status == 4}
                    {l s='Transaction cancelled' mod='dpo'}
                {elseif $status == 0}
                    {l s='Transaction error' mod='dpo'}
                {elseif $status == 1}
                    {l s='Transaction successful' mod='dpo'}
                {/if}
            </h1>
            {if $status != 1}
                <p>Please <a href="{$link->getPageLink('cart')}?action=show">{l s='click here' mod='dpo'}</a> to try
                    again.</p>
            {/if}
        </div>
    </div>
    <br/>
{/block}
