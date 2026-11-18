<?php
// =====================================================================
// TEACHER EBD — servidor do app
// - Contas de professores (cadastro com código de convite, login)
// - Sala dos Professores (posts, comentários, curtidas, aulas compartilhadas)
// - Ponte com a IA (a chave fica SÓ aqui no servidor, em config.php)
// Os dados ficam num arquivo SQLite na pasta /dados (bloqueada para a web).
// =====================================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
@set_time_limit(300);
date_default_timezone_set('America/Sao_Paulo');

$config = require __DIR__ . '/config.php';

function responder($status, $dados) {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
function erro($status, $msg) { responder($status, ['error' => $msg]); }
function agora() { return gmdate('Y-m-d\TH:i:s\Z'); }
function txt($v, $max) {
    $v = is_string($v) ? trim($v) : '';
    return mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') erro(405, 'Método não permitido.');

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$acao = $_GET['acao'] ?? ($in['acao'] ?? '');

// ---------------------------------------------------------------------
// Banco de dados (SQLite)
// ---------------------------------------------------------------------
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pasta = __DIR__ . '/dados';
    if (!is_dir($pasta)) @mkdir($pasta, 0755, true);
    if (!file_exists($pasta . '/.htaccess')) {
        @file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        erro(500, 'O servidor não tem SQLite ativado. Avise o responsável pelo app.');
    }
    $pdo = new PDO('sqlite:' . $pasta . '/teacher.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS usuarios(
        id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
        igreja TEXT, senha_hash TEXT NOT NULL, admin INTEGER DEFAULT 0, ativo INTEGER DEFAULT 1, criado TEXT);
      CREATE TABLE IF NOT EXISTS sessoes(
        token_hash TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL, expira INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS posts(
        id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, licao TEXT, tipo TEXT,
        titulo TEXT, texto TEXT, aula TEXT, criado TEXT);
      CREATE TABLE IF NOT EXISTS comentarios(
        id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, usuario_id INTEGER NOT NULL,
        texto TEXT, criado TEXT);
      CREATE TABLE IF NOT EXISTS curtidas(
        post_id INTEGER NOT NULL, usuario_id INTEGER NOT NULL, PRIMARY KEY(post_id, usuario_id));
      CREATE TABLE IF NOT EXISTS uso(
        dia TEXT NOT NULL, usuario_id INTEGER NOT NULL, n INTEGER DEFAULT 0, PRIMARY KEY(dia, usuario_id));
      CREATE TABLE IF NOT EXISTS aulas(
        id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER NOT NULL, titulo TEXT, dados TEXT,
        criado TEXT, atualizado TEXT);
      CREATE INDEX IF NOT EXISTS idx_aulas_usuario ON aulas(usuario_id, id DESC);
      CREATE INDEX IF NOT EXISTS idx_posts_criado ON posts(id DESC);
      CREATE INDEX IF NOT EXISTS idx_com_post ON comentarios(post_id);
    ");
    return $pdo;
}

function publicoUsuario($u) {
    return ['id' => (int)$u['id'], 'nome' => $u['nome'], 'email' => $u['email'],
            'igreja' => $u['igreja'], 'admin' => (bool)$u['admin']];
}

function criarSessao($uid) {
    $token = bin2hex(random_bytes(32));
    $st = db()->prepare('INSERT INTO sessoes(token_hash, usuario_id, expira) VALUES(?,?,?)');
    $st->execute([hash('sha256', $token), $uid, time() + 60 * 60 * 24 * 30]);
    db()->prepare('DELETE FROM sessoes WHERE expira < ?')->execute([time()]);
    return $token;
}

function usuarioAtual($obrigatorio = true) {
    $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if ($token !== '') {
        $st = db()->prepare('SELECT u.* FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id
                             WHERE s.token_hash = ? AND s.expira > ?');
        $st->execute([hash('sha256', $token), time()]);
        $u = $st->fetch();
        if ($u && (int)$u['ativo'] === 1) return $u;
    }
    if ($obrigatorio) erro(401, 'Faça login para continuar.');
    return null;
}

function exigirAdmin($u) { if (!(int)$u['admin']) erro(403, 'Somente o administrador pode fazer isso.'); }

function codigosConvite($config) {
    $c = $config['convites'] ?? ($config['senhas'] ?? []);
    return array_values(array_filter((array)$c, fn($x) => is_string($x) && $x !== '' && strpos($x, 'TROQUE') === false));
}

// ---------------------------------------------------------------------
// Ações
// ---------------------------------------------------------------------
switch ($acao) {

// ----- Contas -----
case 'cadastrar': {
    $nome = txt($in['nome'] ?? '', 80);
    $email = mb_strtolower(txt($in['email'] ?? '', 120));
    $igreja = txt($in['igreja'] ?? '', 80);
    $senha = is_string($in['senha'] ?? null) ? $in['senha'] : '';
    $convite = is_string($in['convite'] ?? null) ? trim($in['convite']) : '';

    $ok = false;
    foreach (codigosConvite($config) as $c) { if (hash_equals($c, $convite)) { $ok = true; break; } }
    if (!$ok) { sleep(1); erro(403, 'Código de convite inválido. Peça o código ao responsável.'); }
    if (mb_strlen($nome) < 2) erro(400, 'Digite seu nome.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro(400, 'E-mail inválido.');
    if (strlen($senha) < 6) erro(400, 'A senha precisa ter pelo menos 6 caracteres.');

    $existe = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
    $existe->execute([$email]);
    if ($existe->fetch()) erro(409, 'Já existe uma conta com este e-mail. Use "Entrar".');

    $primeiro = (int)db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
    $st = db()->prepare('INSERT INTO usuarios(nome, email, igreja, senha_hash, admin, ativo, criado) VALUES(?,?,?,?,?,1,?)');
    $st->execute([$nome, $email, $igreja, password_hash($senha, PASSWORD_DEFAULT), $primeiro ? 1 : 0, agora()]);
    $uid = (int)db()->lastInsertId();
    $u = db()->query('SELECT * FROM usuarios WHERE id = ' . $uid)->fetch();
    responder(200, ['token' => criarSessao($uid), 'usuario' => publicoUsuario($u)]);
}

case 'entrar': {
    $email = mb_strtolower(txt($in['email'] ?? '', 120));
    $senha = is_string($in['senha'] ?? null) ? $in['senha'] : '';
    $st = db()->prepare('SELECT * FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($senha, $u['senha_hash'])) { sleep(1); erro(401, 'E-mail ou senha incorretos.'); }
    if ((int)$u['ativo'] !== 1) erro(403, 'Sua conta está bloqueada. Fale com o responsável.');
    responder(200, ['token' => criarSessao((int)$u['id']), 'usuario' => publicoUsuario($u)]);
}

case 'eu': {
    $u = usuarioAtual();
    responder(200, ['usuario' => publicoUsuario($u)]);
}

case 'sair': {
    $token = $_SERVER['HTTP_X_TOKEN'] ?? '';
    if ($token) db()->prepare('DELETE FROM sessoes WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    responder(200, ['ok' => true]);
}

case 'trocar_senha': {
    $u = usuarioAtual();
    $atual = is_string($in['atual'] ?? null) ? $in['atual'] : '';
    $nova = is_string($in['nova'] ?? null) ? $in['nova'] : '';
    if (!password_verify($atual, $u['senha_hash'])) erro(401, 'Senha atual incorreta.');
    if (strlen($nova) < 6) erro(400, 'A nova senha precisa ter pelo menos 6 caracteres.');
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
    responder(200, ['ok' => true]);
}

// ----- Sala dos Professores -----
case 'posts': {
    $u = usuarioAtual();
    $busca = txt($in['busca'] ?? '', 80);
    $tipo = txt($in['tipo'] ?? '', 20);
    $antes = (int)($in['antes'] ?? 0);
    $where = []; $p = [':eu' => $u['id']];
    if ($busca !== '') { $where[] = '(p.licao LIKE :b OR p.titulo LIKE :b OR p.texto LIKE :b OR u.nome LIKE :b)'; $p[':b'] = '%' . $busca . '%'; }
    if ($tipo !== '') { $where[] = 'p.tipo = :t'; $p[':t'] = $tipo; }
    if ($antes > 0) { $where[] = 'p.id < :a'; $p[':a'] = $antes; }
    $sql = 'SELECT p.id, p.usuario_id, p.licao, p.tipo, p.titulo, p.texto, p.criado,
              (p.aula IS NOT NULL AND p.aula <> \'\') AS tem_aula,
              u.nome AS autor, u.igreja AS igreja,
              (SELECT COUNT(*) FROM curtidas c WHERE c.post_id = p.id) AS curtidas,
              (SELECT COUNT(*) FROM curtidas c WHERE c.post_id = p.id AND c.usuario_id = :eu) AS eu_curti,
              (SELECT COUNT(*) FROM comentarios m WHERE m.post_id = p.id) AS comentarios
            FROM posts p JOIN usuarios u ON u.id = p.usuario_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.id DESC LIMIT 20';
    $st = db()->prepare($sql);
    $st->execute($p);
    $lista = [];
    foreach ($st->fetchAll() as $r) {
        $r['id'] = (int)$r['id'];
        $r['tem_aula'] = (bool)$r['tem_aula'];
        $r['curtidas'] = (int)$r['curtidas'];
        $r['eu_curti'] = (bool)$r['eu_curti'];
        $r['comentarios'] = (int)$r['comentarios'];
        $r['pode_apagar'] = ((int)$r['usuario_id'] === (int)$u['id']) || (bool)$u['admin'];
        unset($r['usuario_id']);
        $lista[] = $r;
    }
    responder(200, ['posts' => $lista]);
}

case 'post_criar': {
    $u = usuarioAtual();
    $tipos = ['ideia', 'duvida', 'experiencia', 'aula'];
    $tipo = in_array($in['tipo'] ?? '', $tipos, true) ? $in['tipo'] : 'ideia';
    $licao = txt($in['licao'] ?? '', 100);
    $titulo = txt($in['titulo'] ?? '', 160);
    $texto = txt($in['texto'] ?? '', 8000);
    if ($titulo === '' && $texto === '') erro(400, 'Escreva um título ou um texto.');
    $aula = null;
    if (isset($in['aula']) && is_array($in['aula'])) {
        $aula = json_encode($in['aula'], JSON_UNESCAPED_UNICODE);
        if (strlen($aula) > 600000) erro(400, 'A aula é grande demais para compartilhar.');
    }
    $hoje = db()->prepare("SELECT COUNT(*) FROM posts WHERE usuario_id = ? AND criado >= ?");
    $hoje->execute([$u['id'], gmdate('Y-m-d\T00:00:00\Z')]);
    if ((int)$hoje->fetchColumn() >= 40) erro(429, 'Limite de publicações de hoje atingido.');
    $st = db()->prepare('INSERT INTO posts(usuario_id, licao, tipo, titulo, texto, aula, criado) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$u['id'], $licao, $tipo, $titulo, $texto, $aula, agora()]);
    responder(200, ['id' => (int)db()->lastInsertId()]);
}

case 'post_aula': {
    usuarioAtual();
    $st = db()->prepare('SELECT aula FROM posts WHERE id = ?');
    $st->execute([(int)($in['id'] ?? 0)]);
    $a = $st->fetchColumn();
    if (!$a) erro(404, 'Aula não encontrada.');
    responder(200, ['aula' => json_decode($a, true)]);
}

case 'post_apagar': {
    $u = usuarioAtual();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT usuario_id FROM posts WHERE id = ?');
    $st->execute([$id]);
    $dono = $st->fetchColumn();
    if ($dono === false) erro(404, 'Publicação não encontrada.');
    if ((int)$dono !== (int)$u['id'] && !(int)$u['admin']) erro(403, 'Você só pode apagar suas publicações.');
    db()->prepare('DELETE FROM comentarios WHERE post_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM curtidas WHERE post_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    responder(200, ['ok' => true]);
}

case 'curtir': {
    $u = usuarioAtual();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT 1 FROM curtidas WHERE post_id = ? AND usuario_id = ?');
    $st->execute([$id, $u['id']]);
    if ($st->fetch()) db()->prepare('DELETE FROM curtidas WHERE post_id = ? AND usuario_id = ?')->execute([$id, $u['id']]);
    else db()->prepare('INSERT OR IGNORE INTO curtidas(post_id, usuario_id) VALUES(?,?)')->execute([$id, $u['id']]);
    $n = db()->prepare('SELECT COUNT(*) FROM curtidas WHERE post_id = ?');
    $n->execute([$id]);
    responder(200, ['curtidas' => (int)$n->fetchColumn()]);
}

case 'comentarios': {
    $u = usuarioAtual();
    $st = db()->prepare('SELECT m.id, m.usuario_id, m.texto, m.criado, u.nome AS autor, u.igreja
                         FROM comentarios m JOIN usuarios u ON u.id = m.usuario_id
                         WHERE m.post_id = ? ORDER BY m.id ASC LIMIT 200');
    $st->execute([(int)($in['post_id'] ?? 0)]);
    $lista = [];
    foreach ($st->fetchAll() as $r) {
        $r['id'] = (int)$r['id'];
        $r['pode_apagar'] = ((int)$r['usuario_id'] === (int)$u['id']) || (bool)$u['admin'];
        unset($r['usuario_id']);
        $lista[] = $r;
    }
    responder(200, ['comentarios' => $lista]);
}

case 'comentar': {
    $u = usuarioAtual();
    $pid = (int)($in['post_id'] ?? 0);
    $texto = txt($in['texto'] ?? '', 3000);
    if ($texto === '') erro(400, 'Escreva o comentário.');
    $ex = db()->prepare('SELECT 1 FROM posts WHERE id = ?');
    $ex->execute([$pid]);
    if (!$ex->fetch()) erro(404, 'Publicação não encontrada.');
    db()->prepare('INSERT INTO comentarios(post_id, usuario_id, texto, criado) VALUES(?,?,?,?)')
        ->execute([$pid, $u['id'], $texto, agora()]);
    responder(200, ['ok' => true]);
}

case 'comentario_apagar': {
    $u = usuarioAtual();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare('SELECT usuario_id FROM comentarios WHERE id = ?');
    $st->execute([$id]);
    $dono = $st->fetchColumn();
    if ($dono === false) erro(404, 'Comentário não encontrado.');
    if ((int)$dono !== (int)$u['id'] && !(int)$u['admin']) erro(403, 'Você só pode apagar seus comentários.');
    db()->prepare('DELETE FROM comentarios WHERE id = ?')->execute([$id]);
    responder(200, ['ok' => true]);
}

// ----- Minhas aulas -----
case 'aula_salvar': {
    $u = usuarioAtual();
    $id = (int)($in['id'] ?? 0);
    $titulo = txt($in['titulo'] ?? '', 160);
    if (!isset($in['aula']) || !is_array($in['aula'])) erro(400, 'Nada para salvar.');
    $dados = json_encode($in['aula'], JSON_UNESCAPED_UNICODE);
    if (strlen($dados) > 800000) erro(400, 'A aula é grande demais para salvar.');
    if ($id > 0) {
        $st = db()->prepare('UPDATE aulas SET titulo = ?, dados = ?, atualizado = ? WHERE id = ? AND usuario_id = ?');
        $st->execute([$titulo, $dados, agora(), $id, $u['id']]);
        if ($st->rowCount() > 0) responder(200, ['id' => $id]);
    }
    $n = db()->prepare('SELECT COUNT(*) FROM aulas WHERE usuario_id = ?');
    $n->execute([$u['id']]);
    if ((int)$n->fetchColumn() >= 300) erro(400, 'Você já tem muitas aulas salvas. Apague algumas antigas.');
    db()->prepare('INSERT INTO aulas(usuario_id, titulo, dados, criado, atualizado) VALUES(?,?,?,?,?)')
        ->execute([$u['id'], $titulo, $dados, agora(), agora()]);
    responder(200, ['id' => (int)db()->lastInsertId()]);
}

case 'aulas_minhas': {
    $u = usuarioAtual();
    $st = db()->prepare('SELECT id, titulo, criado, atualizado FROM aulas WHERE usuario_id = ? ORDER BY atualizado DESC LIMIT 200');
    $st->execute([$u['id']]);
    $lista = array_map(function ($r) { $r['id'] = (int)$r['id']; return $r; }, $st->fetchAll());
    responder(200, ['aulas' => $lista]);
}

case 'aula_abrir': {
    $u = usuarioAtual();
    $st = db()->prepare('SELECT id, titulo, dados FROM aulas WHERE id = ? AND usuario_id = ?');
    $st->execute([(int)($in['id'] ?? 0), $u['id']]);
    $r = $st->fetch();
    if (!$r) erro(404, 'Aula não encontrada.');
    responder(200, ['id' => (int)$r['id'], 'titulo' => $r['titulo'], 'aula' => json_decode($r['dados'], true)]);
}

case 'aula_apagar': {
    $u = usuarioAtual();
    db()->prepare('DELETE FROM aulas WHERE id = ? AND usuario_id = ?')->execute([(int)($in['id'] ?? 0), $u['id']]);
    responder(200, ['ok' => true]);
}

// ----- Administração -----
case 'admin_usuarios': {
    $u = usuarioAtual(); exigirAdmin($u);
    $hoje = date('Y-m-d');
    $st = db()->prepare('SELECT u.id, u.nome, u.email, u.igreja, u.admin, u.ativo, u.criado,
        (SELECT COUNT(*) FROM posts p WHERE p.usuario_id = u.id) AS posts,
        (SELECT COALESCE(SUM(n),0) FROM uso s WHERE s.usuario_id = u.id) AS pedidos_total,
        (SELECT COALESCE(SUM(n),0) FROM uso s WHERE s.usuario_id = u.id AND s.dia = ?) AS pedidos_hoje
        FROM usuarios u ORDER BY u.id ASC');
    $st->execute([$hoje]);
    $lista = array_map(function ($r) {
        foreach (['id','admin','ativo','posts','pedidos_total','pedidos_hoje'] as $k) $r[$k] = (int)$r[$k];
        return $r;
    }, $st->fetchAll());
    responder(200, ['usuarios' => $lista]);
}

case 'admin_alterar': {
    $u = usuarioAtual(); exigirAdmin($u);
    $id = (int)($in['id'] ?? 0);
    if ($id === (int)$u['id']) erro(400, 'Você não pode alterar a sua própria conta aqui.');
    if (isset($in['ativo'])) {
        db()->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?')->execute([$in['ativo'] ? 1 : 0, $id]);
        if (!$in['ativo']) db()->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
    }
    if (isset($in['admin'])) db()->prepare('UPDATE usuarios SET admin = ? WHERE id = ?')->execute([$in['admin'] ? 1 : 0, $id]);
    responder(200, ['ok' => true]);
}

case 'admin_nova_senha': {
    $u = usuarioAtual(); exigirAdmin($u);
    $id = (int)($in['id'] ?? 0);
    $nova = substr(str_replace(['+','/','='], '', base64_encode(random_bytes(9))), 0, 8);
    db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
    db()->prepare('DELETE FROM sessoes WHERE usuario_id = ?')->execute([$id]);
    responder(200, ['senha' => $nova]);
}

// ----- IA -----
case 'ia': {
    $u = usuarioAtual();
    if (empty($config['chave_ia']) || strpos($config['chave_ia'], 'COLE_AQUI') !== false) {
        erro(500, 'A chave da IA ainda não foi configurada no arquivo config.php.');
    }
    $hoje = date('Y-m-d');
    $total = db()->prepare('SELECT COALESCE(SUM(n),0) FROM uso WHERE dia = ?');
    $total->execute([$hoje]);
    if ((int)$total->fetchColumn() >= (int)($config['limite_diario'] ?? 60)) {
        erro(429, 'O limite de uso de hoje do app foi atingido. Tente amanhã.');
    }
    $meu = db()->prepare('SELECT COALESCE(n,0) FROM uso WHERE dia = ? AND usuario_id = ?');
    $meu->execute([$hoje, $u['id']]);
    $limiteProf = (int)($config['limite_por_professor'] ?? 25);
    if ((int)$meu->fetchColumn() >= $limiteProf && !(int)$u['admin']) {
        erro(429, 'Você atingiu o seu limite de uso de hoje. Tente amanhã.');
    }
    db()->prepare('INSERT INTO uso(dia, usuario_id, n) VALUES(?,?,1)
                   ON CONFLICT(dia, usuario_id) DO UPDATE SET n = n + 1')->execute([$hoje, $u['id']]);

    $mensagens = $in['messages'] ?? null;
    if (!is_array($mensagens) || count($mensagens) === 0 || count($mensagens) > 30) erro(400, 'Pedido inválido.');
    $total = 0; $limpas = [];
    foreach ($mensagens as $m) {
        $papel = $m['role'] ?? ''; $texto = $m['content'] ?? '';
        if (!in_array($papel, ['user', 'assistant'], true) || !is_string($texto)) erro(400, 'Pedido inválido.');
        $total += mb_strlen($texto);
        $limpas[] = ['role' => $papel, 'content' => $texto];
    }
    if ($total > 120000) erro(400, 'O texto da lição é grande demais. Escolha menos páginas.');
    $maxTokens = min(12000, max(500, (int)($in['max_tokens'] ?? 8000)));

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 280, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $config['chave_ia'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $config['modelo'] ?? 'claude-sonnet-5-5',
            'max_tokens' => $maxTokens,
            'messages' => $limpas,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $resposta = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);
    if ($resposta === false) erro(502, 'Não consegui falar com a IA: ' . $erroCurl);
    $dados = json_decode($resposta, true);
    if ($status !== 200) {
        $msg = $dados['error']['message'] ?? 'Erro desconhecido.';
        if ($status === 401) $msg = 'A chave da IA no config.php está errada.';
        if ($status === 429) $msg = 'Muitos pedidos à IA agora. Aguarde um minuto.';
        if ($status === 529) $msg = 'A IA está sobrecarregada. Tente em alguns minutos.';
        if ($status === 400 && stripos($msg, 'credit') !== false) $msg = 'Acabaram os créditos da IA. Recarregue no painel da Anthropic.';
        erro($status === 429 ? 429 : 502, $msg);
    }
    $texto = '';
    foreach (($dados['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $texto .= $b['text']; }
    responder(200, ['text' => $texto, 'stop' => $dados['stop_reason'] ?? null]);
}

default:
    erro(400, 'Ação desconhecida.');
}
