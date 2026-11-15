<?php

/*
|--------------------------------------------------------------------------
| VATSSA Fork
|--------------------------------------------------------------------------
|
| VATSSA is one division with one roster, so everything applies across the
| hub: roles can't be given in a single FIR, and Control Center's roster sync
| gives anyone active in any area, or with a visiting endorsement, one
| Controller role across the hub without an area map.
|
| On unless VATSSA_HUB_WIDE=false, which only the test suite sets, to keep
| upstream's per-FIR tests running against upstream's behaviour.
|
*/

return [

    'hub_wide' => (bool) env('VATSSA_HUB_WIDE', true),

];
