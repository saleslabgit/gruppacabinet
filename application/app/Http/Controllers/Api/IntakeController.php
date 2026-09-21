<?php

namespace App\Http\Controllers\Api;

use App\Integration\ApiErrors;
use App\Integration\IntakeData;
use App\Integration\IntakeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntakeController
{
    public function psychologist(Request $request, IntakeService $service): Response
    {
        return $service->submit(ApiErrors::requestId($request), 'psychologists', IntakeData::psychologist($request));
    }

    public function application(Request $request, IntakeService $service): Response
    {
        return $service->submit(ApiErrors::requestId($request), 'group-applications', IntakeData::application($request));
    }
}
