<?php
// tests/Unit/SelfEstimateTest.php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PDO;

class SelfEstimateTest extends TestCase {
    private $pdo;
    private $test_token = 'test_token_999';

    protected function setUp(): void {
        // Set unit test flag to prevent exit and direct email sending
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        // Set secure token in environment variable
        putenv("SELF_ESTIMATE_API_TOKEN={$this->test_token}");

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create mock tables
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_name TEXT,
                contact_name TEXT,
                email TEXT,
                phone_number TEXT,
                role TEXT,
                email_notification_enabled INTEGER DEFAULT 1,
                parent_id INTEGER
            )
        ");

        $this->pdo->exec("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER,
                project_name TEXT,
                billing_company_name TEXT,
                billing_phone_number TEXT,
                status TEXT,
                req_permit INTEGER DEFAULT 0,
                req_wall INTEGER DEFAULT 0,
                req_skin INTEGER DEFAULT 0,
                req_sky INTEGER DEFAULT 0,
                req_opt_kisohari INTEGER DEFAULT 0,
                initial_est_amount INTEGER,
                initial_est_date TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE project_specs (
                project_id INTEGER PRIMARY KEY
            )
        ");

        $this->pdo->exec("
            CREATE TABLE estimates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                base_price INTEGER,
                area REAL,
                grade_price INTEGER,
                total_price INTEGER,
                note TEXT,
                pdf_drive_file_id TEXT,
                req_permit INTEGER,
                req_wall INTEGER,
                req_skin INTEGER,
                req_sky INTEGER,
                inputs_json TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                sender_id INTEGER,
                thread_type TEXT,
                message_text TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE magic_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                token TEXT,
                expires_at TEXT
            )
        ");

        require_once __DIR__ . '/../../api_self_estimate.php';
    }

    protected function tearDown(): void {
        putenv("SELF_ESTIMATE_API_TOKEN"); // Clear env token
    }

    public function testInvalidApiTokenReturns403() {
        $data = [
            'api_token' => 'wrong_token',
            'email' => 'test@example.com',
            'company_name' => 'Test Co',
            'contact_name' => 'John Doe'
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertFalse($result['success']);
        $this->assertEquals(403, $result['code']);
        $this->assertStringContainsString('Invalid API Token', $result['message']);
    }

    public function testMissingRequiredFieldsReturns400() {
        $data = [
            'api_token' => $this->test_token,
            'email' => '', // Empty
            'company_name' => 'Test Co',
            'contact_name' => 'John Doe'
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertFalse($result['success']);
        $this->assertEquals(400, $result['code']);
        $this->assertStringContainsString('Missing required fields', $result['message']);
    }

    public function testNewUserRegistrationCreatesUserAndProjectAndMagicLink() {
        $data = [
            'api_token' => $this->test_token,
            'email' => 'newuser@example.com',
            'company_name' => 'New Company',
            'contact_name' => 'New Contact',
            'phone_number' => '090-0000-0000',
            'project_name' => 'New House Project',
            'req_permit' => 1,
            'req_wall' => 0,
            'req_skin' => 1,
            'req_sky' => 0,
            'req_opt_kisohari' => 0,
            'estimate_details' => 'Permit option: Yes\nTotal: 150,000 Yen'
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_new_user']);
        $this->assertEquals(200, $result['code']);
        $this->assertNotEmpty($result['token']);

        // Verify User was created
        $stmtUser = $this->pdo->query("SELECT * FROM users WHERE email = 'newuser@example.com'");
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertEquals('New Company', $user['company_name']);
        $this->assertEquals('client', $user['role']);

        // Verify Project was created
        $stmtProj = $this->pdo->query("SELECT * FROM projects WHERE id = " . $result['project_id']);
        $project = $stmtProj->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($project);
        $this->assertEquals('New House Project', $project['project_name']);
        $this->assertEquals('quote_req', $project['status']);
        $this->assertEquals(1, $project['req_permit']);
        $this->assertEquals(1, $project['req_skin']);
        $this->assertEquals(0, $project['req_wall']);

        // Verify specs initialized
        $stmtSpecs = $this->pdo->query("SELECT * FROM project_specs WHERE project_id = " . $result['project_id']);
        $this->assertNotFalse($stmtSpecs->fetch());

        // Verify Message was saved in chat
        $stmtMsg = $this->pdo->query("SELECT * FROM messages WHERE project_id = " . $result['project_id']);
        $msg = $stmtMsg->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($msg);
        $this->assertStringContainsString('Permit option', $msg['message_text']);

        // Verify Magic link token saved
        $stmtLink = $this->pdo->query("SELECT * FROM magic_links WHERE user_id = " . $user['id']);
        $link = $stmtLink->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($link);
        $this->assertEquals($result['token'], $link['token']);
    }

    public function testExistingUserCreatesProjectLinkedToExistingAccount() {
        // Pre-insert existing user with empty contact_name and phone_number
        $stmtUser = $this->pdo->prepare("
            INSERT INTO users (id, company_name, contact_name, email, phone_number, role) 
            VALUES (100, 'Existing Corp', '', 'existing@example.com', '', 'client')
        ");
        $stmtUser->execute();

        $data = [
            'api_token' => $this->test_token,
            'email' => 'existing@example.com',
            'company_name' => 'Existing Corp',
            'contact_name' => 'Updated Contact Name', // Should fill because it was empty
            'phone_number' => '03-1111-2222',        // Should fill
            'project_name' => 'Second Project',
            'req_wall' => 1,
            'estimate_details' => 'Wall option'
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_new_user']); // Should be false
        $this->assertEquals(200, $result['code']);
        $this->assertEmpty($result['token']); // No token generated for existing users

        // Verify profile contact name was updated because it was empty
        $stmtCheckUser = $this->pdo->query("SELECT contact_name, phone_number FROM users WHERE id = 100");
        $user = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Updated Contact Name', $user['contact_name']);
        $this->assertEquals('03-1111-2222', $user['phone_number']);

        // Verify project is linked to user 100
        $stmtProj = $this->pdo->query("SELECT * FROM projects WHERE id = " . $result['project_id']);
        $project = $stmtProj->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($project);
        $this->assertEquals(100, $project['client_id']);
        $this->assertEquals('Second Project', $project['project_name']);
        $this->assertEquals(1, $project['req_wall']);
    }

    public function testExistingUserDoesNotOverwriteExistingProfileDetails() {
        // Pre-insert existing user with non-empty contact_name and phone_number
        $stmtUser = $this->pdo->prepare("
            INSERT INTO users (id, company_name, contact_name, email, phone_number, role) 
            VALUES (101, 'Existing Corp', 'Original Name', 'existing2@example.com', '03-9999-9999', 'client')
        ");
        $stmtUser->execute();

        $data = [
            'api_token' => $this->test_token,
            'email' => 'existing2@example.com',
            'company_name' => 'Existing Corp',
            'contact_name' => 'Should Not Overwrite',
            'phone_number' => '03-0000-0000',
            'project_name' => 'Another Project'
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertTrue($result['success']);

        // Verify profile contact name was NOT updated because it was not empty
        $stmtCheckUser = $this->pdo->query("SELECT contact_name, phone_number FROM users WHERE id = 101");
        $user = $stmtCheckUser->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Original Name', $user['contact_name']);
        $this->assertEquals('03-9999-9999', $user['phone_number']);
    }

    public function testEstimateDataAndInitialPricingAreSavedCorrectly() {
        $data = [
            'api_token' => $this->test_token,
            'email' => 'est@example.com',
            'company_name' => 'Est Co',
            'contact_name' => 'Est Name',
            'phone_number' => '03-1234-5678',
            'project_name' => 'Est Project',
            'base_price' => 100000,
            'area' => 120.5,
            'grade_price' => 50000,
            'total_price' => 150000,
            'note' => [
                ['name' => '基本料金', 'price' => 100000],
                ['name' => '仕様加算', 'price' => 50000]
            ],
            'inputs_json' => [
                'req_permit' => 1,
                'area' => 120.5
            ],
            'req_permit' => 1,
            'req_skin' => 1
        ];

        $result = handleSelfEstimate($data, $this->pdo, true);

        $this->assertTrue($result['success']);

        // Verify estimates record was created
        $stmtEst = $this->pdo->query("SELECT * FROM estimates WHERE project_id = " . $result['project_id']);
        $est = $stmtEst->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($est);
        $this->assertEquals(100000, $est['base_price']);
        $this->assertEquals(120.5, $est['area']);
        $this->assertEquals(50000, $est['grade_price']);
        $this->assertEquals(150000, $est['total_price']);
        
        $noteDecoded = json_decode($est['note'], true);
        $this->assertCount(2, $noteDecoded);
        $this->assertEquals('基本料金', $noteDecoded[0]['name']);
        
        $inputsDecoded = json_decode($est['inputs_json'], true);
        $this->assertEquals(1, $inputsDecoded['req_permit']);

        // Verify project.initial_est_amount was set to tax included (150,000 * 1.1 = 165,000)
        $stmtProj = $this->pdo->query("SELECT initial_est_amount, initial_est_date FROM projects WHERE id = " . $result['project_id']);
        $project = $stmtProj->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(165000, $project['initial_est_amount']);
        $this->assertEquals(date('Y-m-d'), $project['initial_est_date']);
    }
}
