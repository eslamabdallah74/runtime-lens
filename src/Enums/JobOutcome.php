<?php

namespace RuntimeLens\Enums;

enum JobOutcome: string
{
    case Processed = 'processed';
    case Failed = 'failed';
}
