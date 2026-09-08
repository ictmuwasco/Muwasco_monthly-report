<?php

namespace Tests\Feature;

use App\Models\MonthlyData;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\ReportingPeriod;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesSqliteLegacySchema;
use Tests\TestCase;

/**
 * Reports endpoint tests (M13) — JSON preview + PDF download.
 * In-memory SQLite only — the live MySQL database is NEVER touched.
 */
class ReportsTest extends TestCase
{
    use UsesSqliteLegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSqliteSchema();
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'admin1', 'email' => 'admin1@example.com',
            'full_name' => 'Admin One', 'password' => Hash::make('secret123'),
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Seed one category, two parameters, and three submitted periods with data.
     *
     * @return array{periods: Collection, parameter: array{p1:int,p2:int}}
     */
    private function seedReportData(): array
    {
        $cat = ParameterCategory::create(['name' => 'Production', 'display_order' => 1]);
        $p1 = Parameter::create(['category_id' => $cat->id, 'code' => 'PR1',
            'label' => 'Water produced', 'unit' => 'm3', 'data_type' => 'number']);
        $p2 = Parameter::create(['category_id' => $cat->id, 'code' => 'PR2',
            'label' => 'Energy used', 'unit' => 'kWh', 'data_type' => 'number']);

        $periods = [];
        foreach (['2025-01', '2025-02', '2025-03'] as $ym) {
            $periods[] = ReportingPeriod::create([
                'name' => $ym, 'month_year' => $ym.'-01',
                'start_date' => $ym.'-01', 'end_date' => $ym.'-28',
                'status' => 'submitted',
            ]);
        }

        MonthlyData::create(['month_id' => $periods[0]->id, 'parameter_id' => $p1->id, 'value' => '100']);
        MonthlyData::create(['month_id' => $periods[1]->id, 'parameter_id' => $p1->id, 'value' => '110']);
        MonthlyData::create(['month_id' => $periods[0]->id, 'parameter_id' => $p2->id, 'value' => '20']);
        // p2 has no value for periods[1] — should render as '-'.

        return ['periods' => $periods, 'parameter' => ['p1' => $p1->id, 'p2' => $p2->id]];
    }

    private function monthQuery(array $ids): string
    {
        return http_build_query(['months' => $ids]);
    }

    /* ── Auth ─────────────────────────────────────────────── */

    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/v1/reports/preview?months[]=1&months[]=2&months[]=3')
            ->assertUnauthorized();

        $this->getJson('/api/v1/reports/pdf?months[]=1&months[]=2&months[]=3')
            ->assertUnauthorized();
    }

    /* ── Preview ──────────────────────────────────────────── */

    public function test_preview_requires_at_least_three_months(): void
    {
        $this->seedReportData();

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->getJson('/api/v1/reports/preview?months[]=1&months[]=2');

        $res->assertStatus(422)
            ->assertJsonValidationErrors('months');
    }

    public function test_preview_rejects_unknown_months(): void
    {
        $this->seedReportData();

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->getJson('/api/v1/reports/preview?months[]=1&months[]=2&months[]=999');

        $res->assertStatus(422)
            ->assertJsonValidationErrors('months');
    }

    public function test_preview_returns_report_dataset(): void
    {
        $data = $this->seedReportData();
        $ids = array_column($data['periods'], 'id');

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->getJson('/api/v1/reports/preview?'.$this->monthQuery($ids));

        $res->assertOk()
            ->assertJsonStructure(['periods', 'categories', 'generated_at']);

        $json = $res->json();

        $this->assertCount(3, $json['periods']);
        $this->assertCount(1, $json['categories']);

        $parameters = $json['categories'][0]['parameters'];
        $this->assertCount(2, $parameters);

        // Parameter values are aligned to periods chronologically.
        $p1 = collect($parameters)->firstWhere('code', 'PR1');
        $this->assertSame(['100', '110', null], $p1['values']);
    }

    /* ── PDF ──────────────────────────────────────────────── */

    public function test_pdf_requires_at_least_three_months(): void
    {
        $this->seedReportData();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->get('/api/v1/reports/pdf?months[]=1&months[]=2')
            ->assertStatus(422);
    }

    public function test_pdf_downloads_monitoring_report(): void
    {
        $data = $this->seedReportData();
        $ids = array_column($data['periods'], 'id');

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->get('/api/v1/reports/pdf?'.$this->monthQuery($ids));

        $res->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=muwasco_monitoring_report.pdf');
    }

    public function test_pdf_body_contains_report_markers(): void
    {
        $data = $this->seedReportData();
        $ids = array_column($data['periods'], 'id');

        $res = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->admin()))
            ->get('/api/v1/reports/pdf?'.$this->monthQuery($ids));

        $content = $res->getContent();

        // Valid PDF binary framing (the text layer is FlateDecode-compressed,
        // so we only assert the PDF container markers).
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertStringContainsString('%%EOF', $content);
    }
}
