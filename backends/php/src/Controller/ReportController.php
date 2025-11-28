<?php

namespace App\Controller;

use App\Service\Report\ReportDataService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends AbstractController
{
    public function __construct(
        private readonly ReportDataService $reportService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/reports/data', name: 'api_reports_data', methods: ['GET'])]
    public function getData(Request $request): JsonResponse
    {
        $this->logger->debug('ReportController.getData.start', [
            'query' => $request->query->all(),
        ]);

        try {
            $filter = [];

            // Date Range
            $dateFrom = $request->query->get('dateFrom');
            $dateTo = $request->query->get('dateTo');

            if ($dateFrom) {
                $filter['>=createdTime'] = $dateFrom;
            }
            if ($dateTo) {
                $filter['<=createdTime'] = $dateTo;
            }

            // Employee
            $employeeId = $request->query->get('employeeId');
            if ($employeeId) {
                $filter['=assignedById'] = $employeeId;
            }

            // Project (Name)
            $projectName = $request->query->get('projectName');
            if ($projectName) {
                // Assuming exact match for now
                $filter['=ufCrm87_1764265641'] = $projectName;
            }

            // Fetch Data
            $data = $this->reportService->getReportData($filter);

            return new JsonResponse([
                'items' => $data,
                'count' => count($data),
            ]);

        } catch (\Throwable $throwable) {
            $this->logger->error('ReportController.getData.error', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => $throwable->getMessage(),
                'type' => get_class($throwable),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ], 500);
        }
    }
}
