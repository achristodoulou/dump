<?php

/**
 * Main task
 */
task('deploy', [

    'deploy:prepare',	
	
	/**
	 * Preparing server for deployment.
	 */
	task('deploy:prepare', function () {
		\Deployer\Task\Context::get()->getServer()->connect();
		// Check if shell is POSIX-compliant
		try {
			cd(''); // To run command as raw.
			$result = run('echo $0')->toString();
			if ($result == 'stdin: is not a tty') {
				throw new RuntimeException(
					"Looks like ssh inside another ssh.\n" .
					"Help: http://goo.gl/gsdLt9"
				);
			}
		} catch (\RuntimeException $e) {
			$formatter = \Deployer\Deployer::get()->getHelper('formatter');
			$errorMessage = [
				"Shell on your server is not POSIX-compliant. Please change to sh, bash or similar.",
				"Usually, you can change your shell to bash by running: chsh -s /bin/bash",
			];
			write($formatter->formatBlock($errorMessage, 'error', true));
			throw $e;
		}
		// Set the deployment timezone
		if (!date_default_timezone_set(env('timezone'))) {
			date_default_timezone_set('UTC');
		}
		run('if [ ! -d {{deploy_path}} ]; then mkdir -p {{deploy_path}}; fi');
		// Create releases dir.
		run("cd {{deploy_path}} && if [ ! -d releases ]; then mkdir releases; fi");
		// Create shared dir.
		run("cd {{deploy_path}} && if [ ! -d shared ]; then mkdir shared; fi");
	})->desc('Preparing server for deploy');

    'deploy:release',
	
	/**
	 * Release
	 */
	task('deploy:release', function () {
		$release = date('YmdHis');
		$releasePath = "{{deploy_path}}/releases/$release";
		$i = 0;
		while (is_dir(env()->parse($releasePath)) && $i < 42) {
			$releasePath .= '.' . ++$i;
		}
		run("mkdir $releasePath");
		run("cd {{deploy_path}} && if [ -h release ]; then rm release; fi");
		run("ln -s $releasePath {{deploy_path}}/release");
	})->desc('Prepare release');
	
    'deploy:update_code',
	
	/**
	 * Update project code
	 */
	task('deploy:update_code', function () {
		$repository = get('repository');
		$branch = env('branch');
		$gitCache = env('git_cache');
		$depth = $gitCache ? '' : '--depth 1';
		if (input()->hasOption('tag')) {
			$tag = input()->getOption('tag');
		}
		$at = '';
		if (!empty($tag)) {
			$at = "-b $tag";
		} elseif (!empty($branch)) {
			$at = "-b $branch";
		}
		$releases = env('releases_list');
		if ($gitCache && isset($releases[1])) {
			try {
				run("git clone $at --recursive -q --reference {{deploy_path}}/releases/{$releases[1]} --dissociate $repository  {{release_path}} 2>&1");
			} catch (RuntimeException $exc) {
				// If {{deploy_path}}/releases/{$releases[1]} has a failed git clone, is empty, shallow etc, git would throw error and give up. So we're forcing it to act without reference in this situation
				run("git clone $at --recursive -q $repository {{release_path}} 2>&1");
			}
		} else {
			// if we're using git cache this would be identical to above code in catch - full clone. If not, it would create shallow clone.
			run("git clone $at $depth --recursive -q $repository {{release_path}} 2>&1");
		}
	})->desc('Updating code');
	
    'deploy:create_cache_dir',
	
	/**
	 * Create cache dir
	 */
	task('deploy:create_cache_dir', function () {
		// Set cache dir
		env('cache_dir', '{{release_path}}/' . trim(get('var_dir'), '/') . '/cache');
		// Remove cache dir if it exist
		run('if [ -d "{{cache_dir}}" ]; then rm -rf {{cache_dir}}; fi');
		// Create cache dir
		run('mkdir -p {{cache_dir}}');
		// Set rights
		run("chmod -R g+w {{cache_dir}}");
	})->desc('Create cache dir');
	
    'deploy:shared',
	
	/**
	 * Create symlinks for shared directories and files.
	 */
	task('deploy:shared', function () {
		$sharedPath = "{{deploy_path}}/shared";
		foreach (get('shared_dirs') as $dir) {
			// Remove from source
			run("if [ -d $(echo {{release_path}}/$dir) ]; then rm -rf {{release_path}}/$dir; fi");
			// Create shared dir if it does not exist
			run("mkdir -p $sharedPath/$dir");
			// Create path to shared dir in release dir if it does not exist
			// (symlink will not create the path and will fail otherwise)
			run("mkdir -p `dirname {{release_path}}/$dir`");
			// Symlink shared dir to release dir
			run("ln -nfs $sharedPath/$dir {{release_path}}/$dir");
		}
		foreach (get('shared_files') as $file) {
			$dirname = dirname($file);
			// Remove from source
			run("if [ -f $(echo {{release_path}}/$file) ]; then rm -rf {{release_path}}/$file; fi");
			// Ensure dir is available in release
			run("if [ ! -d $(echo {{release_path}}/$dirname) ]; then mkdir -p {{release_path}}/$dirname;fi");
			// Create dir of shared file
			run("mkdir -p $sharedPath/" . $dirname);
			// Touch shared
			run("touch $sharedPath/$file");
			// Symlink shared dir to release dir
			run("ln -nfs $sharedPath/$file {{release_path}}/$file");
		}
	})->desc('Creating symlinks for shared files');
	
    'deploy:writable',
	
	/**
	 * Make writable dirs.
	 */
	task('deploy:writable', function () {
		$dirs = join(' ', get('writable_dirs'));
		$sudo = get('writable_use_sudo') ? 'sudo' : '';
		$httpUser = get('http_user');
		if (!empty($dirs)) {
			try {
				if (null === $httpUser) {
					$httpUser = run("ps aux | grep -E '[a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx' | grep -v root | head -1 | cut -d\  -f1")->toString();
				}
				cd('{{release_path}}');
				// Try OS-X specific setting of access-rights
				if (strpos(run("chmod 2>&1; true"), '+a') !== false) {
					if (!empty($httpUser)) {
						run("$sudo chmod +a \"$httpUser allow delete,write,append,file_inherit,directory_inherit\" $dirs");
					}
					run("$sudo chmod +a \"`whoami` allow delete,write,append,file_inherit,directory_inherit\" $dirs");
				// Try linux ACL implementation with unsafe fail-fallback to POSIX-way
				} elseif (commandExist('setfacl')) {
					if (!empty($httpUser)) {
						if (!empty($sudo)) {
							run("$sudo setfacl -R -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
							run("$sudo setfacl -dR -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dirs");
						} else {
							// When running without sudo, exception may be thrown
							// if executing setfacl on files created by http user (in directory that has been setfacl before).
							// These directories/files should be skipped.
							// Now, we will check each directory for ACL and only setfacl for which has not been set before.
							$writeableDirs = get('writable_dirs');
							foreach ($writeableDirs as $dir) {
								// Check if ACL has been set or not
								$hasfacl = run("getfacl -p $dir | grep \"^user:$httpUser:.*w\" | wc -l")->toString();
								// Set ACL for directory if it has not been set before
								if (!$hasfacl) {
									run("setfacl -R -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
									run("setfacl -dR -m u:\"$httpUser\":rwX -m u:`whoami`:rwX $dir");
								}
							}
						}
					} else {
						run("$sudo chmod 777 -R $dirs");
					}
				// If we are not on OS-X and have no ACL installed use POSIX
				} else {
					run("$sudo chmod 777 -R $dirs");
				}
			} catch (\RuntimeException $e) {
				$formatter = \Deployer\Deployer::get()->getHelper('formatter');
				$errorMessage = [
					"Unable to setup correct permissions for writable dirs.                  ",
					"You need to configure sudo's sudoers files to not prompt for password,",
					"or setup correct permissions manually.                                  ",
				];
				write($formatter->formatBlock($errorMessage, 'error', true));
				throw $e;
			}
		}
	})->desc('Make writable dirs');
	
    'deploy:assets',
	
	/**
	 * Normalize asset timestamps
	 */
	task('deploy:assets', function () {
		$assets = implode(' ', array_map(function ($asset) {
			return "{{release_path}}/$asset";
		}, get('assets')));
		$time = date('Ymdhi.s');
		run("find $assets -exec touch -t $time {} ';' &> /dev/null || true");
	})->desc('Normalize asset timestamps');
	
    'deploy:vendors',
	
	/**
	 * Installing vendors tasks.
	 */
	task('deploy:vendors', function () {
		$composer = get('composer_command');
		
		if (! commandExist($composer)) {
			run("cd {{release_path}} && curl -sS https://getcomposer.org/installer | php");
			$composer = 'php composer.phar';
		}
		$composerEnvVars = env('env_vars') ? 'export ' . env('env_vars') . ' &&' : '';
		
		#env('composer_options', 'install --no-dev --verbose --prefer-dist --optimize-autoloader --no-progress --no-interaction');
		run("cd {{release_path}} && $composerEnvVars $composer {{composer_options}}");
	})->desc('Installing vendors');
	
    'deploy:assetic:dump',
	
	/**
	 * Dump all assets to the filesystem
	 */
	task('deploy:assetic:dump', function () {
		if (!get('dump_assets')) {
			return;
		}
		run('php {{release_path}}/' . trim(get('bin_dir'), '/') . '/console assetic:dump --env={{env}} --no-debug');
	})->desc('Dump assets');
	
    'deploy:cache:warmup',
	
	/**
	 * Warm up cache
	 */
	task('deploy:cache:warmup', function () {
		run('php {{release_path}}/' . trim(get('bin_dir'), '/') . '/console cache:warmup  --env={{env}} --no-debug');
	})->desc('Warm up cache');
	
	
	/**
	 * Remove app_dev.php files
	 */
	task('deploy:clear_controllers', function () {
		run("rm -f {{release_path}}/web/app_*.php");
		run("rm -f {{release_path}}/web/config.php");
	})->setPrivate();
	
	after('deploy:update_code', 'deploy:clear_controllers');
	
	
    'deploy:symlink',
					
	/**
	 * Create symlink to last release.
	 */
	task('deploy:symlink', function () {
		run("cd {{deploy_path}} && ln -sfn {{release_path}} current"); // Atomic override symlink.
		run("cd {{deploy_path}} && rm release"); // Remove release link.
	})->desc('Creating symlink to release');
	
    'cleanup',
	
	/**
	 * Cleanup old releases.
	 */
	task('cleanup', function () {
		$releases = env('releases_list');
		$keep = get('keep_releases');
		while ($keep > 0) {
			array_shift($releases);
			--$keep;
		}
		foreach ($releases as $release) {
			run("rm -rf {{deploy_path}}/releases/$release");
		}
		run("cd {{deploy_path}} && if [ -e release ]; then rm release; fi");
		run("cd {{deploy_path}} && if [ -h release ]; then rm release; fi");
	})->desc('Cleaning up old releases');
	
])->desc('Deploy your project');


/**
 * Main task
 */
task('deploy', [
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:create_cache_dir',
    'deploy:shared',
    'deploy:writable',
    'deploy:assets',
    'deploy:vendors',
    'deploy:assetic:dump',
    'deploy:cache:warmup',
    'deploy:symlink',
    'cleanup',
])->desc('Deploy your project');
after('deploy', 'success');
