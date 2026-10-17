<?php

namespace App\Catalog;

/** Internal: thrown inside SystemCatalog::sync()'s transaction to roll back a dry run. Never escapes sync(). */
final class CatalogDryRun extends \RuntimeException {}
