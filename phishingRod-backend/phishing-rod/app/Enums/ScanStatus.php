<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case SubmittedToUrlscan = 'submitted_to_urlscan';
    case WaitingForUrlscan = 'waiting_for_urlscan';
    case UrlscanComplete = 'urlscan_complete';
    case DomFetched = 'dom_fetched';
    case Predicting = 'predicting';
    case Completed = 'completed';
    case Failed = 'failed';
}
