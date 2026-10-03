<?php

namespace RuntimeLens\Enums;

enum RecordingMode: string
{
    case App = 'app';
    case Tests = 'tests';
    case Off = 'off';
}
