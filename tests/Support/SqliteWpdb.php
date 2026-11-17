<?php
// phpcs:disable -- a $wpdb whose class name marks the SQLite driver, as WordPress' SQLite integration does.

namespace Swapline\Tests\Support;

class SqliteWpdb extends FakeWpdb {}
