const { execSync } = require('child_process');
const readline = require('readline');

const rl = readline.createInterface({
	input: process.stdin,
	output: process.stdout,
});

const run = (cmd) => execSync(cmd, { stdio: 'inherit' });

const getStatus = () => execSync('git status --porcelain').toString().trim();

rl.question('Commit message: ', (message) => {
	const msg = (message || '').trim();
	if (!msg) {
		console.error('Error: empty message.');
		rl.close();
		process.exit(1);
	}

	run('git status -sb');
	run('git diff --stat');

	const status = getStatus();
	if (!status) {
		console.log('No changes to commit.');
		rl.close();
		process.exit(0);
	}

	rl.question('Continue with add/commit/push? (y/N): ', (answer) => {
		const normalized = (answer || '').trim().toLowerCase();
		if (normalized !== 'y' && normalized !== 'yes') {
			console.log('Operation cancelled.');
			rl.close();
			process.exit(0);
		}

		run('git add .');
		run(`git commit -m "${msg.replace(/"/g, '\\"')}"`);
		run('git push');

		rl.close();
	});
});
