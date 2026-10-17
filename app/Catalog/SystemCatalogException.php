<?php

namespace App\Catalog;

/** TEN-11: a firm tried to create, change or delete a platform-owned reference row. Rendered as 409 `system_catalog`. */
class SystemCatalogException extends \RuntimeException {}
