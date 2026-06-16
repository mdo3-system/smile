<?php
namespace App\Services;

use App\Repositories\Interfaces\EstimateRepositoryInterface;
use App\Domain\Entities\Estimate;

class EstimateCalculatorService
{
    private EstimateRepositoryInterface $estimateRepo;

    public function __construct(EstimateRepositoryInterface $estimateRepo)
    {
        $this->estimateRepo = $estimateRepo;
    }

    public function saveEstimate(int $projectId, array $data, ?string $pdfDriveId): bool
    {
        $estimate = new Estimate(
            null,
            $projectId,
            (int)($data['base_price'] ?? 0),
            (float)($data['area'] ?? 0.0),
            (int)($data['grade_price'] ?? 0),
            (int)($data['total_price'] ?? 0),
            json_decode($data['note'] ?? '[]', true) ?: [],
            $pdfDriveId,
            (bool)($data['req_permit'] ?? false),
            (bool)($data['req_wall'] ?? false),
            (bool)($data['req_skin'] ?? false),
            (bool)($data['req_sky'] ?? false),
            $data['inputs_json'] ?? null
        );

        return $this->estimateRepo->save($estimate);
    }

    /**
     * サーバーサイドでの見積もり金額計算ロジック（将来的にフロントエンドのJSを置き換えるためのもの）
     */
    public function calculateTotal(array $params): int
    {
        $total = 0;
        
        // 許容応力度
        if (!empty($params['req_permit'])) {
            $base = $params['base_permit'] ?? 0;
            $area = $params['area_permit'] ?? 0;
            $areaExtra = $area > 150 ? ceil($area - 150) * 600 : 0;
            $total += $base + $areaExtra;
            // 他の割増等は省略 (サンプルロジック)
        }

        // 税込み計算
        $tax = round($total * 0.1);
        return $total + $tax;
    }
}
