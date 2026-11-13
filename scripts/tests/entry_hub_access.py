#!/usr/bin/env python3
"""Check the real endpoint guards with synthetic settings and no database."""
import base64
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
helper = root / "entry_hub_access.php"
for endpoint in ("entry_hub.php", "booking_entry.php"):
    source = (root / endpoint).read_text()
    # Run the endpoint itself, replacing only the application bootstrap.
    source = source.replace("require __DIR__ . '/bootstrap.php';", "")
    for setting in ({}, {"entry_hub_enabled": "0"}, {"entry_hub_enabled": "invalid"}):
        for method in ("GET", "POST"):
            for logged_in in (False, True):
                fixture = (
                    "require " + json.dumps(str(helper)) + ";"
                    "$pdo=new stdClass();"
                    "$currentUser=" + ("['id'=>1,'role'=>'developer']" if logged_in else "null") + ";"
                    "$_SERVER['REQUEST_METHOD']=" + json.dumps(method) + ";"
                    "$_GET=['code'=>'synthetic-code','item_id'=>123];"
                    "function getSiteSettings($pdo){return json_decode("
                    + json.dumps(json.dumps(setting)) + ",true);}"
                    "function fetchPages(...$args){throw new RuntimeException('Page lookup reached');}"
                    "function ensure_bookings_tables(...$args){throw new RuntimeException('Entry lookup reached');}"
                    "register_shutdown_function(function(){echo '|HTTP:'.http_response_code();});"
                    "eval(base64_decode('" + base64.b64encode(source.removeprefix("<?php").encode()).decode() + "'));"
                )
                result = subprocess.run(["php", "-r", fixture], capture_output=True, text=True, check=True)
                assert result.stdout == "Entry Hub is unavailable.|HTTP:404", (endpoint, result.stdout)
                assert not result.stderr, result.stderr

result = subprocess.run(
    ["php", "-r", "require " + json.dumps(str(helper)) +
     ";requireEntryHubEnabled(['entry_hub_enabled'=>'1']);echo 'enabled';"],
    capture_output=True, text=True, check=True,
)
assert result.stdout == "enabled"
assert not result.stderr
print("Entry Hub: both endpoints reject disabled/missing/invalid settings before data access; GET/POST and anonymous/signed-in requests covered; enabled guard passes.")
