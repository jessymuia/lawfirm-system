<?php

/**
 * DATA-14: money. A firm keeps its books in one base currency; it may invoice in others, each invoice fixing its
 * exchange rate to the base currency when it is issued (docs/MONEY.md). The base currency here is the default for a
 * firm that has not chosen one (Settings → Currencies and tax). Time rates and expenses are in the base currency.
 */
return [
    'base_currency' => strtoupper((string) env('MONEY_BASE_CURRENCY', 'KES')),
];
