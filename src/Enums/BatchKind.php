<?php

namespace RuntimeLens\Enums;

enum BatchKind: string
{
    case Request = 'request';
    case Job = 'job';
    case Command = 'command';
}
