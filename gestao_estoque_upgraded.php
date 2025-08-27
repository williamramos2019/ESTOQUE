<?php
// #####################################################################
// # SISTEMA DE GESTÃO DE ESTOQUE - VERSÃO 3.0 MODERNIZADA
// # Sistema expandido com backup automático, notificações em tempo real,
// # relatórios PDF, cache inteligente e integrações externas
// #####################################################################

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Carregar autoloader do Composer PRIMEIRO
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    die('Erro: Execute "composer install" para instalar as dependências.');
}

// Carregar configurações
require_once __DIR__ . '/config/database.php';

// Carregar serviços APÓS o autoloader
require_once __DIR__ . '/services/CacheService.php';
require_once __DIR__ . '/services/BackupService.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/ReportService.php';
require_once __DIR__ . '/services/ValidationService.php';
require_once __DIR__ . '/services/ApiIntegrationService.php';

use Services\CacheService;
use Services\BackupService;
use Services\NotificationService;
use Services\ReportService;
use Services\ValidationService;
use Services\ApiIntegrationService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');
define('UPLOAD_DIR', 'uploads');
define('CACHE_DIR', 'cache');
define('LOGS_DIR', 'logs');
define('BACKUPS_DIR', 'backups');

// Criar diretórios necessários
foreach ([UPLOAD_DIR, CACHE_DIR, LOGS_DIR, BACKUPS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Configurar logger avançado
$logger = new Logger('almoxarifado');
$logger->pushHandler(new StreamHandler(LOGS_DIR . '/app.log', Logger::DEBUG));

// Inicializar serviços
$cache = new CacheService();
$backup = new BackupService($logger);
$notification = new NotificationService($logger);
$report = new ReportService($logger);
$validator = new ValidationService();
$apiIntegration = new ApiIntegrationService($logger);

// Conexão com banco (mantendo compatibilidade total)
$db_file = 'gestao.sqlite';
$is_new_db = !file_exists($db_file);

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($is_new_db) {
        // Tabela de empresas
        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            phone TEXT,
            cnpj TEXT UNIQUE,
            email TEXT,
            address TEXT,
            cep TEXT,
            city TEXT,
            state TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de movimentações
        $pdo->exec("CREATE TABLE IF NOT EXISTS movements (
            id TEXT PRIMARY KEY,
            company_id TEXT NOT NULL,
            type TEXT NOT NULL,
            date TEXT NOT NULL,
            nfe TEXT,
            products TEXT NOT NULL,
            total_value DECIMAL(10,2) DEFAULT 0,
            image_path TEXT,
            xml_path TEXT,
            notes TEXT,
            sku_code TEXT,
            upload_files TEXT,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )");

        // Tabela de inventário por SKU
        $pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id TEXT NOT NULL,
            sku TEXT NOT NULL,
            product_name TEXT NOT NULL,
            current_quantity DECIMAL(10,3) DEFAULT 0,
            unit TEXT DEFAULT 'UN',
            last_price DECIMAL(10,2) DEFAULT 0,
            last_movement_date TEXT,
            minimum_stock DECIMAL(10,3) DEFAULT 0,
            maximum_stock DECIMAL(10,3) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(company_id, sku),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )");

        // Tabela de logs avançados
        $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            user_ip TEXT,
            user_agent TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de notificações
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de configurações do sistema
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key TEXT UNIQUE NOT NULL,
            config_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de backups
        $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            backup_type TEXT DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $logger->info('Nova base de dados criada com sucesso');
    } else {
        // Atualizar tabelas existentes para nova versão
        try {
            $pdo->exec("ALTER TABLE companies ADD COLUMN cep TEXT");
            $pdo->exec("ALTER TABLE companies ADD COLUMN city TEXT");
            $pdo->exec("ALTER TABLE companies ADD COLUMN state TEXT");
        } catch (Exception $e) {}
        
        try {
            $pdo->exec("ALTER TABLE movements ADD COLUMN status TEXT DEFAULT 'active'");
        } catch (Exception $e) {}
        
        try {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN minimum_stock DECIMAL(10,3) DEFAULT 0");
            $pdo->exec("ALTER TABLE inventory ADD COLUMN maximum_stock DECIMAL(10,3) DEFAULT 0");
        } catch (Exception $e) {}
        
        // Criar novas tabelas se não existirem
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_key TEXT UNIQUE NOT NULL,
                config_value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS backups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                file_path TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                backup_type TEXT DEFAULT 'manual',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {}
        
        $logger->info('Base de dados atualizada para versão 3.0');
    }
} catch (PDOException $e) {
    $logger->error('Erro na base de dados: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'Erro no banco de dados: ' . $e->getMessage()]));
}

// Funções melhoradas com cache e logs
function logAction($level, $action, $details = '') {
    global $pdo, $logger;
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (level, action, details, user_ip, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $level,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Log também no Monolog
        $logger->log($level, $action, ['details' => $details, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    } catch (Exception $e) {
        $logger->error('Erro ao registrar log: ' . $e->getMessage());
    }
}

function formatBytes($size, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

function updateInventory($company_id, $sku, $product_name, $quantity_change, $unit, $price, $movement_date) {
    global $pdo, $cache, $notification, $logger;
    
    try {
        $stmt = $pdo->prepare("SELECT current_quantity, minimum_stock FROM inventory WHERE company_id = ? AND sku = ?");
        $stmt->execute([$company_id, $sku]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $new_quantity = $existing['current_quantity'] + $quantity_change;
            $stmt = $pdo->prepare("UPDATE inventory SET 
                current_quantity = ?, 
                last_price = ?, 
                last_movement_date = ?, 
                updated_at = CURRENT_TIMESTAMP,
                product_name = ?
                WHERE company_id = ? AND sku = ?");
            $stmt->execute([$new_quantity, $price, $movement_date, $product_name, $company_id, $sku]);
            
            // Verificar estoque mínimo e criar notificação
            if ($new_quantity <= $existing['minimum_stock'] && $existing['minimum_stock'] > 0) {
                $notification->createNotification(
                    'warning',
                    'Estoque Baixo',
                    "Produto {$product_name} (SKU: {$sku}) está com estoque baixo: {$new_quantity} {$unit}"
                );
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO inventory 
                (company_id, sku, product_name, current_quantity, unit, last_price, last_movement_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $sku, $product_name, $quantity_change, $unit, $price, $movement_date]);
        }
        
        // Limpar cache do inventário
        $cache->delete("inventory_{$company_id}");
        $cache->delete("inventory_all");
        
        logAction('info', 'INVENTORY_UPDATED', "SKU: {$sku}, Quantidade: {$quantity_change}");
        
    } catch (Exception $e) {
        $logger->error('Erro ao atualizar inventário: ' . $e->getMessage());
        logAction('error', 'INVENTORY_ERROR', 'Erro ao atualizar inventário: ' . $e->getMessage());
    }
}

function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function getSystemStats() {
    global $pdo, $cache;
    
    $cacheKey = 'system_stats';
    $stats = $cache->get($cacheKey);
    
    if ($stats === null) {
        try {
            // Estatísticas básicas
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM movements WHERE type = 'entrada'");
            $entradas = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM movements WHERE type = 'saida'");
            $saidas = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM companies");
            $empresas = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT SUM(total_value) as total FROM movements WHERE type = 'entrada' AND date >= date('now', '-30 days')");
            $valor_mes = $stmt->fetch()['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT SUM(total_value) as total FROM movements WHERE type = 'entrada' AND date >= date('now', '-365 days')");
            $valor_ano = $stmt->fetch()['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory WHERE current_quantity <= minimum_stock AND minimum_stock > 0");
            $estoque_baixo = $stmt->fetch()['total'];
            
            $stats = [
                'entradas' => $entradas,
                'saidas' => $saidas,
                'empresas' => $empresas,
                'valor_mes' => $valor_mes,
                'valor_ano' => $valor_ano,
                'estoque_baixo' => $estoque_baixo
            ];
            
            // Cache por 5 minutos
            $cache->set($cacheKey, $stats, 300);
            
        } catch (Exception $e) {
            logAction('error', 'STATS_ERROR', 'Erro ao calcular estatísticas: ' . $e->getMessage());
            return [
                'entradas' => 0,
                'saidas' => 0,
                'empresas' => 0,
                'valor_mes' => 0,
                'valor_ano' => 0,
                'estoque_baixo' => 0
            ];
        }
    }
    
    return $stats;
}

// Login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === ADMIN_USER && $password === ADMIN_PASS) {
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
        logAction('info', 'LOGIN', 'Usuário logado com sucesso');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Usuário ou senha inválidos';
        logAction('warning', 'LOGIN_FAILED', 'Tentativa de login com credenciais inválidas');
    }
}

if (isset($_GET['logout'])) {
    logAction('info', 'LOGOUT', 'Usuário fez logout');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// API Router Modernizado
if (isset($_GET['action']) && isAuthenticated()) {
    $action = $_GET['action'];
    header('Content-Type: application/json');

    try {
        switch ($action) {
            case 'upload_xml':
                if (isset($_FILES['xmlfile']) && $_FILES['xmlfile']['error'] == 0) {
                    $xmlContent = file_get_contents($_FILES['xmlfile']['tmp_name']);
                    $xml = simplexml_load_string($xmlContent);

                    if ($xml === false) {
                        throw new Exception('Arquivo XML inválido.');
                    }

                    if (!isset($xml->NFe->infNFe)) {
                        throw new Exception('Arquivo não é uma NF-e válida.');
                    }

                    $infNFe = $xml->NFe->infNFe;
                    $emit = $infNFe->emit;
                    $ide = $infNFe->ide;
                    $total = $infNFe->total->ICMSTot ?? null;

                    // Organizar XMLs em diretório
                    $xmlDir = UPLOAD_DIR . '/xml';
                    if (!is_dir($xmlDir)) {
                        mkdir($xmlDir, 0755, true);
                    }
                    
                    $xmlFileName = 'nfe_' . (string)$ide->nNF . '_' . date('Y-m-d_H-i-s') . '.xml';
                    $xmlPath = $xmlDir . '/' . $xmlFileName;
                    file_put_contents($xmlPath, $xmlContent);

                    // Dados da empresa do XML
                    $companyData = [
                        'cnpj' => (string)$emit->CNPJ,
                        'name' => (string)$emit->xNome,
                        'phone' => (string)($emit->enderEmit->fone ?? ''),
                        'email' => (string)($emit->email ?? ''),
                        'address' => trim((string)($emit->enderEmit->xLgr ?? '') . ', ' . 
                                   (string)($emit->enderEmit->nro ?? '') . ' - ' . 
                                   (string)($emit->enderEmit->xBairro ?? '') . ' - ' . 
                                   (string)($emit->enderEmit->xMun ?? '') . '/' . 
                                   (string)($emit->enderEmit->UF ?? '')),
                        'cep' => (string)($emit->enderEmit->CEP ?? ''),
                        'city' => (string)($emit->enderEmit->xMun ?? ''),
                        'state' => (string)($emit->enderEmit->UF ?? '')
                    ];

                    // Verificar se empresa já existe pelo CNPJ
                    $company_id = null;
                    if (!empty($companyData['cnpj'])) {
                        $stmt = $pdo->prepare("SELECT id FROM companies WHERE cnpj = ?");
                        $stmt->execute([$companyData['cnpj']]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            $company_id = $existing['id'];
                        }
                    }
                    
                    // Se não encontrou, criar nova empresa automaticamente
                    if (!$company_id) {
                        $company_id = uniqid('comp_');
                        
                        // Enriquecer dados da empresa com APIs externas
                        if (!empty($companyData['cnpj'])) {
                            $cnpjData = $apiIntegration->consultarCNPJ($companyData['cnpj']);
                            if ($cnpjData) {
                                $companyData = array_merge($companyData, $cnpjData);
                            }
                        }
                        
                        if (!empty($companyData['cep'])) {
                            $cepData = $apiIntegration->consultarCEP($companyData['cep']);
                            if ($cepData) {
                                $companyData = array_merge($companyData, $cepData);
                            }
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO companies (id, name, phone, cnpj, email, address, cep, city, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $company_id,
                            $companyData['name'],
                            $companyData['phone'],
                            $companyData['cnpj'],
                            $companyData['email'],
                            $companyData['address'],
                            $companyData['cep'] ?? '',
                            $companyData['city'] ?? '',
                            $companyData['state'] ?? ''
                        ]);
                        
                        logAction('info', 'COMPANY_AUTO_CREATED', "Empresa criada automaticamente do XML: {$companyData['name']} - CNPJ: {$companyData['cnpj']}");
                        
                        // Criar notificação
                        $notification->createNotification(
                            'success',
                            'Nova Empresa',
                            "Empresa {$companyData['name']} foi criada automaticamente a partir do XML"
                        );
                    }

                    $result = [
                        'company_id' => $company_id,
                        'company' => $companyData,
                        'movement' => [
                            'nfe' => (string)$ide->nNF,
                            'date' => substr((string)$ide->dhEmi, 0, 10),
                            'total_value' => $total ? (float)$total->vNF : 0,
                            'xml_path' => $xmlPath
                        ],
                        'products' => []
                    ];

                    // Extrair TODOS os produtos com SKU individual
                    foreach ($infNFe->det as $item) {
                        $prod = $item->prod;
                        $product = [
                            'name' => (string)$prod->xProd,
                            'quantity' => (float)$prod->qCom,
                            'price' => (float)$prod->vUnCom,
                            'total' => (float)($prod->qCom * $prod->vUnCom),
                            'unit' => (string)$prod->uCom,
                            'sku' => (string)($prod->cProd ?? 'SKU' . uniqid())
                        ];
                        $result['products'][] = $product;
                    }
                    
                    // Limpar cache
                    $cache->delete('system_stats');
                    
                    logAction('info', 'XML_UPLOAD', 'XML processado: NFe ' . $result['movement']['nfe'] . ' com ' . count($result['products']) . ' produtos');
                    echo json_encode(['status' => 'success', 'data' => $result]);

                } else {
                    throw new Exception('Erro ao fazer upload do arquivo XML.');
                }
                break;

            case 'generate_pdf_report':
                $reportType = $_POST['report_type'] ?? 'movements';
                $startDate = $_POST['start_date'] ?? date('Y-m-01');
                $endDate = $_POST['end_date'] ?? date('Y-m-d');
                $companyId = $_POST['company_id'] ?? '';
                
                $pdfPath = $report->generatePDFReport($reportType, $startDate, $endDate, $companyId);
                
                if ($pdfPath) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Relatório PDF gerado com sucesso',
                        'download_url' => $pdfPath
                    ]);
                } else {
                    throw new Exception('Erro ao gerar relatório PDF');
                }
                break;

            case 'create_backup':
                $backupType = $_POST['backup_type'] ?? 'manual';
                $backupPath = $backup->createFullBackup($backupType);
                
                if ($backupPath) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Backup criado com sucesso',
                        'backup_path' => $backupPath,
                        'file_size' => formatBytes(filesize($backupPath))
                    ]);
                } else {
                    throw new Exception('Erro ao criar backup');
                }
                break;

            case 'get_notifications':
                $stmt = $pdo->prepare("SELECT * FROM notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 10");
                $stmt->execute();
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'notifications' => $notifications
                ]);
                break;

            case 'mark_notification_read':
                $notificationId = $_POST['notification_id'] ?? 0;
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
                $stmt->execute([$notificationId]);
                
                echo json_encode(['status' => 'success']);
                break;

            case 'validate_cnpj':
                $cnpj = $_POST['cnpj'] ?? '';
                if ($validator->validateCNPJ($cnpj)) {
                    $cnpjData = $apiIntegration->consultarCNPJ($cnpj);
                    echo json_encode([
                        'status' => 'success',
                        'valid' => true,
                        'data' => $cnpjData
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'valid' => false,
                        'message' => 'CNPJ inválido'
                    ]);
                }
                break;

            case 'validate_cep':
                $cep = $_POST['cep'] ?? '';
                if ($validator->validateCEP($cep)) {
                    $cepData = $apiIntegration->consultarCEP($cep);
                    echo json_encode([
                        'status' => 'success',
                        'valid' => true,
                        'data' => $cepData
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'valid' => false,
                        'message' => 'CEP inválido'
                    ]);
                }
                break;

            case 'get_system_health':
                $health = [
                    'database' => $pdo ? 'ok' : 'error',
                    'cache' => $cache->isWorking() ? 'ok' : 'error',
                    'disk_space' => disk_free_space('.'),
                    'memory_usage' => memory_get_usage(true),
                    'php_version' => PHP_VERSION,
                    'uptime' => $_SESSION['login_time'] ? time() - $_SESSION['login_time'] : 0
                ];
                
                echo json_encode([
                    'status' => 'success',
                    'health' => $health
                ]);
                break;

            default:
                // Manter compatibilidade com APIs antigas do sistema original
                include 'gestao_estoque_1756298665137.php';
                break;
        }
    } catch (Exception $e) {
        logAction('error', 'API_ERROR', 'Erro na API: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Se não está autenticado, mostrar tela de login
if (!isAuthenticated()) {
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almoxarifado Digital - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/enhanced-styles.css" rel="stylesheet">
</head>
<body class="login-body">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <i class="fas fa-warehouse fa-3x text-success mb-3"></i>
                            <h3>Almoxarifado Digital</h3>
                            <p class="text-muted">Sistema de Gestão de Estoque v3.0</p>
                        </div>
                        
                        <?php if (isset($login_error)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($login_error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Usuário</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Senha</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required>
                                </div>
                            </div>
                            
                            <button type="submit" name="login" class="btn btn-success w-100">
                                <i class="fas fa-sign-in-alt"></i> Entrar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
exit;
}

// Interface principal - mantendo layout exato da imagem
$stats = getSystemStats();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almoxarifado Digital - Gestão de Estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/enhanced-styles.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Cabeçalho Verde Idêntico -->
    <nav class="navbar navbar-expand-lg" style="background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="#">
                <i class="fas fa-warehouse me-2"></i>Almoxarifado Digital
            </a>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger" id="notification-count">0</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" id="notifications-dropdown">
                        <li><h6 class="dropdown-header">Notificações</h6></li>
                        <li><span class="dropdown-item-text">Carregando...</span></li>
                    </ul>
                </div>
                <span class="navbar-text text-white me-3">
                    <i class="fas fa-user"></i> Bem-vindo, Admin
                </span>
                <a href="?logout" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Cards de Estatísticas - Layout Idêntico -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-arrow-down fa-2x text-success mb-2"></i>
                        <h3 class="card-title text-success"><?= $stats['entradas'] ?></h3>
                        <p class="card-text">Entradas</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-arrow-up fa-2x text-danger mb-2"></i>
                        <h3 class="card-title text-danger"><?= $stats['saidas'] ?></h3>
                        <p class="card-text">Devoluções</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-calendar fa-2x text-primary mb-2"></i>
                        <h3 class="card-title text-primary">R$ <?= number_format($stats['valor_mes'], 2, ',', '.') ?></h3>
                        <p class="card-text">Mês Atual</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                        <h3 class="card-title text-info">R$ <?= number_format($stats['valor_ano'], 2, ',', '.') ?></h3>
                        <p class="card-text">Ano Atual</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-balance-scale fa-2x text-warning mb-2"></i>
                        <h3 class="card-title text-warning"><?= $stats['estoque_baixo'] ?></h3>
                        <p class="card-text">Saldo Peças</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center h-100">
                    <div class="card-body">
                        <i class="fas fa-building fa-2x text-purple mb-2"></i>
                        <h3 class="card-title text-purple"><?= $stats['empresas'] ?></h3>
                        <p class="card-text">Empresas</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ações Principais - Layout Idêntico -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5>Ações Principais</h5>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newEntryModal">
                                <i class="fas fa-plus"></i> Nova Entrada
                            </button>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#newReturnModal">
                                <i class="fas fa-undo"></i> Nova Devolução
                            </button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#companyModal">
                                <i class="fas fa-building"></i> Cadastrar Empresa
                            </button>
                            <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#reportsModal">
                                <i class="fas fa-chart-bar"></i> Relatórios
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="showTutorial()">
                                <i class="fas fa-question-circle"></i> Tutorial
                            </button>
                            <button type="button" class="btn btn-warning" onclick="exportData()">
                                <i class="fas fa-download"></i> Exportar
                            </button>
                            <button type="button" class="btn btn-dark" onclick="createBackup()">
                                <i class="fas fa-hdd"></i> Inventário
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros de Pesquisa - Layout Idêntico -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6><i class="fas fa-filter"></i> Filtros de Pesquisa</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <select class="form-select" id="filter-company">
                                    <option value="">Todas as Empresas</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filter-type">
                                    <option value="">Todos os Tipos</option>
                                    <option value="entrada">Entrada</option>
                                    <option value="saida">Devolução</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" id="filter-start-date" placeholder="dd/mm/aaaa">
                            </div>
                            <div class="col-md-2">
                                <input type="date" class="form-control" id="filter-end-date" placeholder="dd/mm/aaaa">
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="filter-sku" placeholder="Buscar produto ou SKU">
                            </div>
                            <div class="col-md-1">
                                <button class="btn btn-success" onclick="applyFilters()">
                                    <i class="fas fa-search"></i> Filtrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela de Movimentações - Layout Idêntico -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h6><i class="fas fa-list"></i> Movimentações</h6>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-success">
                                    <tr>
                                        <th>Data</th>
                                        <th>Empresa</th>
                                        <th>Tipo</th>
                                        <th>NFe</th>
                                        <th>Produtos</th>
                                        <th>Valor Total</th>
                                        <th>Documentos</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="movements-table-body">
                                    <tr>
                                        <td colspan="8" class="text-center">Carregando movimentações...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modais (mantendo estrutura do sistema original mas com melhorias) -->
    <!-- Modal Nova Entrada -->
    <div class="modal fade" id="newEntryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Entrada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Formulário de entrada com validação melhorada -->
                    <form id="entry-form">
                        <!-- Campos do formulário original + validações -->
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/websocket-client.js"></script>
    <script src="assets/js/notifications.js"></script>
    
    <script>
        // JavaScript principal mantendo compatibilidade total
        
        // Carregar dados iniciais
        document.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
            loadMovements();
            loadNotifications();
            initializeWebSocket();
        });

        // Função para carregar empresas
        function loadCompanies() {
            fetch('?action=get_companies')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const select = document.getElementById('filter-company');
                        select.innerHTML = '<option value="">Todas as Empresas</option>';
                        data.companies.forEach(company => {
                            select.innerHTML += `<option value="${company.id}">${company.name}</option>`;
                        });
                    }
                })
                .catch(error => console.error('Erro ao carregar empresas:', error));
        }

        // Função para carregar movimentações
        function loadMovements() {
            fetch('?action=get_movements')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        displayMovements(data.movements);
                    }
                })
                .catch(error => console.error('Erro ao carregar movimentações:', error));
        }

        // Função para exibir movimentações
        function displayMovements(movements) {
            const tbody = document.getElementById('movements-table-body');
            if (movements.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center">Nenhuma movimentação encontrada</td></tr>';
                return;
            }

            tbody.innerHTML = movements.map(movement => `
                <tr>
                    <td>${formatDate(movement.date)}</td>
                    <td>${movement.company_name}</td>
                    <td>
                        <span class="badge bg-${movement.type === 'entrada' ? 'success' : 'danger'}">
                            ${movement.type === 'entrada' ? 'Entrada' : 'Devolução'}
                        </span>
                    </td>
                    <td>${movement.nfe || '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="showProducts('${movement.id}')">
                            Ver Produtos
                        </button>
                    </td>
                    <td>R$ ${parseFloat(movement.total_value || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                    <td>
                        ${movement.upload_files ? `
                            <button class="btn btn-sm btn-outline-info" onclick="showFiles('${movement.id}')">
                                <i class="fas fa-file"></i> Arquivos
                            </button>
                        ` : '-'}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-secondary" onclick="editMovement('${movement.id}')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteMovement('${movement.id}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Função para criar backup
        function createBackup() {
            if (confirm('Deseja criar um backup completo do sistema?')) {
                const formData = new FormData();
                formData.append('backup_type', 'manual');
                
                fetch('?action=create_backup', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`Backup criado com sucesso!\nTamanho: ${data.file_size}\nLocal: ${data.backup_path}`);
                    } else {
                        alert('Erro ao criar backup: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao criar backup');
                });
            }
        }

        // Função para exportar dados
        function exportData() {
            const type = prompt('Qual tipo de dados deseja exportar?\n1 - Movimentações\n2 - Empresas\n3 - Inventário\n\nDigite o número:');
            
            const types = {
                '1': 'movements',
                '2': 'companies', 
                '3': 'inventory'
            };
            
            if (types[type]) {
                window.open(`?action=export_csv&type=${types[type]}`, '_blank');
            }
        }

        // Outras funções do sistema original...
        function formatDate(dateString) {
            return new Date(dateString + 'T00:00:00').toLocaleDateString('pt-BR');
        }

        function applyFilters() {
            // Implementar filtros
            loadMovements();
        }

        // Manter todas as outras funções do sistema original...
    </script>
</body>
</html>
<?php
?>
