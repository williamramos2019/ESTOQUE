<?php
/**
 * Ponto de Entrada Principal
 * Sistema de Gestão de Estoque v3.0 - Modernizado
 * 
 * Redireciona para o sistema principal mantendo compatibilidade total
 */

// Verificar se o sistema principal existe
if (file_exists(__DIR__ . '/gestao_estoque_upgraded.php')) {
    // Redirecionar para o sistema modernizado
    header('Location: gestao_estoque_upgraded.php');
    exit;
} elseif (file_exists(__DIR__ . '/gestao_estoque_1756298665137.php')) {
    // Fallback para o sistema original se o modernizado não existir
    header('Location: gestao_estoque_1756298665137.php');
    exit;
} else {
    // Sistema não encontrado - mostrar página de erro amigável
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Almoxarifado Digital - Sistema não encontrado</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .error-container {
                background: white;
                border-radius: 10px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                padding: 3rem;
                text-align: center;
                max-width: 600px;
                margin: 20px;
            }
            .error-icon {
                font-size: 4rem;
                color: #dc3545;
                margin-bottom: 1rem;
            }
            .system-title {
                color: #4CAF50;
                font-weight: 600;
                margin-bottom: 1rem;
            }
            .setup-steps {
                text-align: left;
                background-color: #f8f9fa;
                border-radius: 8px;
                padding: 1.5rem;
                margin: 2rem 0;
            }
            .setup-steps ol {
                margin-bottom: 0;
            }
            .setup-steps li {
                margin-bottom: 0.5rem;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            
            <h1 class="system-title">
                <i class="fas fa-warehouse"></i> Almoxarifado Digital v3.0
            </h1>
            
            <h2 class="text-danger mb-3">Sistema não encontrado</h2>
            
            <p class="text-muted mb-4">
                O arquivo principal do sistema não foi encontrado. 
                Parece que o sistema ainda não foi configurado corretamente.
            </p>
            
            <div class="setup-steps">
                <h5><i class="fas fa-tools text-primary"></i> Passos para configuração:</h5>
                <ol>
                    <li><strong>Instalar dependências:</strong> Execute <code>composer install</code> no terminal</li>
                    <li><strong>Verificar arquivos:</strong> Certifique-se que <code>gestao_estoque_upgraded.php</code> existe</li>
                    <li><strong>Configurar permissões:</strong> Defina permissões 755 para diretórios e 644 para arquivos</li>
                    <li><strong>Criar diretórios:</strong> Certifique-se que <code>uploads/</code>, <code>logs/</code>, <code>cache/</code> e <code>backups/</code> existem</li>
                    <li><strong>Banco de dados:</strong> O arquivo <code>gestao.sqlite</code> será criado automaticamente</li>
                </ol>
            </div>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>Credenciais padrão:</strong><br>
                Usuário: <code>admin</code><br>
                Senha: <code>admin123</code>
            </div>
            
            <div class="mt-4">
                <button onclick="location.reload()" class="btn btn-success me-2">
                    <i class="fas fa-refresh"></i> Recarregar Página
                </button>
                
                <a href="?check=system" class="btn btn-outline-primary">
                    <i class="fas fa-search"></i> Verificar Sistema
                </a>
            </div>
            
            <?php if (isset($_GET['check']) && $_GET['check'] === 'system'): ?>
            <div class="mt-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-clipboard-check"></i> Diagnóstico do Sistema</h6>
                    </div>
                    <div class="card-body text-start">
                        <?php
                        $checks = [
                            'PHP Version' => version_compare(PHP_VERSION, '8.3.0', '>=') ? 'OK (' . PHP_VERSION . ')' : 'ERRO - Requer PHP 8.3+',
                            'Composer Autoload' => file_exists('vendor/autoload.php') ? 'OK' : 'ERRO - Execute composer install',
                            'Sistema Principal' => file_exists('gestao_estoque_upgraded.php') ? 'OK' : 'ERRO - Arquivo não encontrado',
                            'Extensão PDO' => extension_loaded('pdo') ? 'OK' : 'ERRO - PDO não carregado',
                            'Extensão SQLite' => extension_loaded('sqlite3') ? 'OK' : 'ERRO - SQLite não carregado',
                            'Diretório uploads' => is_dir('uploads') ? 'OK' : 'AVISO - Será criado automaticamente',
                            'Diretório logs' => is_dir('logs') ? 'OK' : 'AVISO - Será criado automaticamente',
                            'Diretório cache' => is_dir('cache') ? 'OK' : 'AVISO - Será criado automaticamente',
                            'Diretório backups' => is_dir('backups') ? 'OK' : 'AVISO - Será criado automaticamente',
                            'Permissões de escrita' => is_writable('.') ? 'OK' : 'ERRO - Sem permissão de escrita'
                        ];
                        
                        foreach ($checks as $check => $result) {
                            $icon = strpos($result, 'OK') !== false ? 'check text-success' : 
                                   (strpos($result, 'AVISO') !== false ? 'exclamation text-warning' : 'times text-danger');
                            
                            echo "<div class='mb-2'>";
                            echo "<i class='fas fa-{$icon}'></i> ";
                            echo "<strong>{$check}:</strong> {$result}";
                            echo "</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mt-4 text-muted">
                <small>
                    <i class="fas fa-code"></i> 
                    Sistema de Gestão de Estoque - Versão 3.0 Modernizada<br>
                    Desenvolvido com PHP, SQLite, Bootstrap e tecnologias modernas
                </small>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}
?>
