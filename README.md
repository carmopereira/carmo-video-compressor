# Carmo Video Compressor

Plugin WordPress que comprime vídeos diretamente no servidor, usando um pipeline `ffmpeg` fixo:

```
ffmpeg -i input.mp4 -an -vcodec libx264 -crf 28 -preset slow -pix_fmt yuv420p output.mp4
```

Uma página de admin em **Ferramentas → Video Compressor** permite arrastar (ou escolher) um vídeo, iniciar a compressão, acompanhar o progresso do upload e da compressão, e depois fazer download ou apagar o resultado numa tabela.

## Funcionalidades

- Drag-and-drop ou browse para selecionar o vídeo original.
- Compressão assíncrona em background (não bloqueia o pedido PHP nem trava com `max_execution_time`).
- Barra de progresso do upload e da compressão (via `ffprobe`/`ffmpeg -progress`).
- CPU throttling automático: `nice -n 19` + `-threads` limitado a metade dos cores do servidor (quando disponíveis), sem alterar os parâmetros de codificação pedidos.
- O ficheiro original nunca é guardado — é apagado assim que a compressão termina (com sucesso ou erro).
- Tabela de vídeos comprimidos com download e apagar.
- Um job de cada vez (sem fila de múltiplos uploads em simultâneo).
- Acesso restrito a administradores (`manage_options`).

## Requisitos

- WordPress com suporte a REST API (padrão).
- `ffmpeg` e `ffprobe` instalados no servidor e acessíveis ao processo PHP.
- Node.js + npm apenas para desenvolvimento/build dos assets (não é necessário no servidor de produção — o `build/` já vai versionado).

## Instalação do plugin

1. Copiar (ou fazer symlink de) esta pasta para `wp-content/plugins/carmo-video-compressor`.
2. Ativar o plugin em **Plugins**.
3. Aceder a **Ferramentas → Video Compressor**.

## Instalação do ffmpeg numa VPS Ubuntu

O `ffmpeg` tem de estar instalado e acessível ao PHP (não basta estar instalado apenas no teu computador — em ambientes locais como o Local by WP Engine o PHP pode correr isolado do resto do sistema).

### 1. Instalar via apt (mais simples)

```bash
sudo apt update
sudo apt install -y ffmpeg
```

Confirma a instalação e a versão:

```bash
ffmpeg -version
ffprobe -version
```

O `apt` do Ubuntu costuma trazer uma versão um pouco mais antiga do ffmpeg, mas é suficiente para este pipeline (`libx264` + `yuv420p` são suportados por qualquer build recente).

### 2. Confirmar que o PHP consegue encontrar o binário

O PHP-FPM/Apache corre com o seu próprio `PATH`, que pode não ser o mesmo do teu utilizador SSH. Confirma o caminho absoluto:

```bash
which ffmpeg
which ffprobe
```

Normalmente devolve `/usr/bin/ffmpeg` e `/usr/bin/ffprobe` numa instalação via apt. O plugin já tenta resolver os binários automaticamente (`PATH`, depois localizações comuns como `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin`), mas se o teu servidor tiver uma configuração atípica (ex: `open_basedir`, PHP-FPM com `PATH` muito restrito, ou ffmpeg instalado num caminho fora do comum), define os caminhos explicitamente no `wp-config.php`:

```php
define('CVC_FFMPEG_BIN', '/usr/bin/ffmpeg');
define('CVC_FFPROBE_BIN', '/usr/bin/ffprobe');
define('CVC_NICE_BIN', '/usr/bin/nice');
```

### 3. Confirmar que `shell_exec` não está bloqueado

Alguns hosts desativam funções de execução de shell por segurança. Confirma que `shell_exec` não está na lista de `disable_functions` do `php.ini`:

```bash
php -i | grep disable_functions
```

Se `shell_exec` aparecer nessa lista, o plugin não vai conseguir correr o ffmpeg — é preciso removê-lo da lista (em `php.ini`, ou na configuração do pool PHP-FPM do site) e reiniciar o PHP-FPM.

### 4. (Opcional) Compilar o ffmpeg com mais otimizações

Para a maioria dos casos o pacote do `apt` chega. Se precisares de uma versão mais recente/otimizada, os builds estáticos oficiais são uma alternativa sem precisar de compilar:

```bash
cd /opt
sudo curl -LO https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
sudo tar xf ffmpeg-release-amd64-static.tar.xz
sudo ln -s /opt/ffmpeg-*-amd64-static/ffmpeg /usr/local/bin/ffmpeg
sudo ln -s /opt/ffmpeg-*-amd64-static/ffprobe /usr/local/bin/ffprobe
```

E depois define `CVC_FFMPEG_BIN`/`CVC_FFPROBE_BIN` no `wp-config.php` a apontar para `/usr/local/bin/ffmpeg` e `/usr/local/bin/ffprobe`, se o PHP não os encontrar automaticamente.

## Desenvolvimento

```bash
npm install
npm run start   # watch mode
npm run build   # build de produção (gera build/index.js, build/index.css, build/index.asset.php)
```

Scripts auxiliares:

- `npm run symlink` — cria um symlink desta pasta dentro de `wp-content/plugins` de um site WordPress local.
- `npm run updateGIT` — commit + push interativo.
- `npm run plugin-zip` — gera um `.zip` do plugin pronto a distribuir.

## Licença

GPL-2.0-or-later
