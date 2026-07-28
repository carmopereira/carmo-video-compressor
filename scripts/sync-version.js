const fs = require('fs');
const path = require('path');

// Use process.cwd() to get the project root directory (where package.json lives)
// When run via npm script, process.cwd() points to the project root
const root = process.cwd();
const pkgPath = path.join(root, 'package.json');

if (!fs.existsSync(pkgPath)) {
	console.error('❌ package.json not found!');
	process.exit(1);
}

const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
const version = pkg.version;
const pluginName = pkg.name;

// Find main PHP file
// Try {plugin-name}.php first, then scan any .php at root
let pluginPath = null;
const possiblePhpNames = [
	`${pluginName}.php`,
	`${pluginName.replace(/[^a-z0-9]/gi, '-')}.php`,
];

// Scan for PHP file at root
const rootFiles = fs.readdirSync(root);
for (const file of rootFiles) {
	if (file.endsWith('.php') && !file.startsWith('.')) {
		// Check if it is the main file (contains "Plugin Name:")
		const content = fs.readFileSync(path.join(root, file), 'utf8');
		if (content.includes('Plugin Name:')) {
			pluginPath = path.join(root, file);
			break;
		}
	}
}

if (!pluginPath) {
	console.error('❌ Main PHP file not found!');
	process.exit(1);
}

// Find block.json recursively in src/
function findBlockJson(dir) {
	if (!fs.existsSync(dir)) {
		return null;
	}
	
	const entries = fs.readdirSync(dir, { withFileTypes: true });
	
	for (const entry of entries) {
		const fullPath = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			const found = findBlockJson(fullPath);
			if (found) return found;
		} else if (entry.name === 'block.json') {
			return fullPath;
		}
	}
	return null;
}

const blockJsonPath = findBlockJson(path.join(root, 'src'));

if (!blockJsonPath) {
	console.warn('⚠️  block.json not found in src/. Continuing without updating...');
}

const updateFile = (filePath, updater) => {
	if (!fs.existsSync(filePath)) {
		return;
	}
	const contents = fs.readFileSync(filePath, 'utf8');
	const next = updater(contents);
	if (next !== contents) {
		fs.writeFileSync(filePath, next, 'utf8');
		console.log(`✅ Version updated in ${path.relative(root, filePath)}`);
	}
};

// Update PHP file
updateFile(pluginPath, (contents) => {
	return contents.replace(
		/^\s*\*\s*Version:\s*.*$/m,
		` * Version:           ${version}`
	);
});

// Update block.json if found
if (blockJsonPath) {
	updateFile(blockJsonPath, (contents) => {
		return contents.replace(
			/"version"\s*:\s*"[^"]*"/,
			`"version": "${version}"`
		);
	});
}

// Update Stable tag in readme.txt if found
const readmePath = path.join(root, 'readme.txt');
updateFile(readmePath, (contents) => {
	return contents.replace(
		/^Stable tag:\s*.*$/m,
		`Stable tag: ${version}`
	);
});

console.log(`✅ Version synced: ${version}`);
