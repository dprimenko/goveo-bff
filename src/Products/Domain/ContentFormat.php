<?php

declare(strict_types=1);

namespace App\Products\Domain;

enum ContentFormat: string
{
    case Markdown = 'markdown';
    case Html     = 'html';
    case Plain    = 'plain';
}
