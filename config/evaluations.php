<?php

/*
|--------------------------------------------------------------------------
| Evaluation scoring (D-054)
|--------------------------------------------------------------------------
| Q-033 (approved under D-048): a score is shown on a /5.0 scale, as the mean
| of the answered scaled questions, each normalised to its own scale first -
| so a 10-point question does not weigh twice a 5-point one.
|
| Q-034 (approved under D-048): the pass limit is per template, default 3.0.
| The legacy evaluation_categories table has no column for it, so until the
| D-032 template tables exist every template uses this default.
*/

return [
    'score_max'      => 5,
    'pass_threshold' => 3.0,
];
