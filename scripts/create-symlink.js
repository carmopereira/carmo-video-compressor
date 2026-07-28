#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout
});

function question(query) {
  return new Promise(resolve => rl.question(query, resolve));
}

// Remove surrounding single or double quotes from a path the user pasted in.
// Runs in a loop and strips stray newlines too, since a messy paste (e.g. two
// paste attempts landing on the same line) can leave more than one quote
// character stacked at either end.
function stripQuotes(str) {
  let result = str.replace(/[\r\n]+/g, '').trim();
  let previous;
  do {
    previous = result;
    result = result.replace(/^["']|["']$/g, '').trim();
  } while (result !== previous);
  return result;
}

// If the given path contains a "wp-content" segment anywhere in it, rebuild
// the path up to (and including) "wp-content" and append "plugins".
// This lets the user paste any path inside the site (e.g. a path pointing
// to wp-content/uploads/2026) and still land on the correct plugins folder.
function resolvePluginsDir(inputPath) {
  const resolved = path.resolve(inputPath);
  const parts = resolved.split(path.sep);
  const idx = parts.indexOf('wp-content');

  if (idx === -1) {
    return resolved;
  }

  const wpContentDir = parts.slice(0, idx + 1).join(path.sep) || path.sep;
  return path.join(wpContentDir, 'plugins');
}

async function createSymlink() {
  try {
    // Get current plugin directory (where package.json is)
    const pluginDir = process.cwd();
    const pluginName = path.basename(pluginDir);

    console.log('\n🔗 Create Plugin Symlink\n');
    console.log(`Plugin: ${pluginName}`);
    console.log(`Plugin directory: ${pluginDir}\n`);

    // Ask for target directory
    const targetDir = await question(
      '📁 Enter the full path to the WordPress directory\n' +
      '(e.g. /Users/carmo/Sites/my-site/wp-content/plugins):\n'
    );

    if (!targetDir || !targetDir.trim()) {
      console.log('❌ Directory cannot be empty!');
      rl.close();
      return;
    }

    const cleanTargetDir = stripQuotes(targetDir);

    if (!cleanTargetDir) {
      console.log('❌ Directory cannot be empty!');
      rl.close();
      return;
    }

    if (!path.isAbsolute(cleanTargetDir)) {
      console.log(`❌ "${cleanTargetDir}" is not an absolute path.`);
      console.log('   Check for stray characters left over from pasting (e.g. an extra quote) and try again.');
      rl.close();
      return;
    }

    const targetPath = resolvePluginsDir(cleanTargetDir);
    const symlinkPath = path.join(targetPath, pluginName);

    // Check if the target directory exists
    if (!fs.existsSync(targetPath)) {
      console.log(`❌ The directory ${targetPath} does not exist!`);
      rl.close();
      return;
    }

    // Check if a symlink or folder with the same name already exists
    if (fs.existsSync(symlinkPath)) {
      const stats = fs.lstatSync(symlinkPath);
      if (stats.isSymbolicLink()) {
        console.log(`⚠️  A symlink already exists at ${symlinkPath}`);
        const overwrite = await question('Do you want to overwrite it? (y/n): ');
        if (overwrite.toLowerCase() !== 'y') {
          console.log('❌ Operation cancelled.');
          rl.close();
          return;
        }
        fs.unlinkSync(symlinkPath);
      } else {
        console.log(`❌ A folder/file already exists at ${symlinkPath}`);
        console.log('❌ Cannot create the symlink. Please remove or rename it first.');
        rl.close();
        return;
      }
    }

    // Create the symlink
    fs.symlinkSync(pluginDir, symlinkPath, 'dir');
    
    console.log('\n✅ Symlink created successfully!');
    console.log(`   Source: ${pluginDir}`);
    console.log(`   Target: ${symlinkPath}\n`);

  } catch (error) {
    console.error('\n❌ Error creating symlink:', error.message);
  } finally {
    rl.close();
  }
}

createSymlink();
