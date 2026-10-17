<?php

namespace Tests;

/**
 * TEN-17: a test class that builds its own control plane and firms (multi-tenant tests, isolation tests). In
 * TEST_TENANCY_MODE=multi it is not put inside the shared test firm: it runs exactly as in the default mode, because
 * it already decides the mode itself.
 */
interface OwnsTenancy {}
