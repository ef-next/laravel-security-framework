<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * The framework's three scheduled commands. onOneServer() needs a cache store that
 * all servers share (redis, database); with the array store every server runs them,
 * which is harmless for anchor and verify but wasteful. Replace the address with
 * the one your on-call rotation reads.
 */
Schedule::command('audit:anchor')->everyFiveMinutes()->onOneServer();
Schedule::command('audit:verify')->hourly()->onOneServer()->emailOutputOnFailure('security@example.test');
Schedule::command('security:doctor --no-verify')->everyFifteenMinutes()->onOneServer()->emailOutputOnFailure('security@example.test');
