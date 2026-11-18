# TEACHER EBD

Assistente de preparação de aulas da Escola Bíblica Dominical + Sala dos Professores.

## Arquivos
- `index.html` — o app (telas)
- `api.php` — servidor: contas, Sala dos Professores, Minhas aulas e ponte com a IA
- `.htaccess` — protege `config.php`, a pasta `dados/` e `.git`
- `config.exemplo.php` — modelo da configuração

## No servidor (não vai para o GitHub)
Ficam na pasta `Guardar`, ao lado de `public_html` (fora do site):
- `config.php` — chave da IA, código de convite e limites (copie de `config.exemplo.php`)
- `dados/` — banco SQLite com contas, publicações e aulas salvas
