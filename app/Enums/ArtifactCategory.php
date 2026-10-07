<?php

namespace App\Enums;

enum ArtifactCategory: string
{
    case REPORT = 'REPORT';
    case LOG = 'LOG';
    case MANIFEST = 'MANIFEST';
    case SOURCE_PACKAGE = 'SOURCE_PACKAGE';
    case COURSE_PACKAGE = 'COURSE_PACKAGE';
    case FULL_BACKUP = 'FULL_BACKUP';
    case TECHNICAL_EVIDENCE = 'TECHNICAL_EVIDENCE';
}
