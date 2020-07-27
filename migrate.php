<?php
/**
 * Bram Hammer
 * Copyright (c) 2020.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 * ******************************************************************
 * @category   Command Line
 * @package    bramhammer/imap-backup-dovecot
 * @copyright  Copyright (c) Bram Hammer (http://www.fullstak.nl)
 * @license    MIT
 */

	require __DIR__.'/vendor/autoload.php';
	
	use Symfony\Component\Console\Input\InputArgument;
	use Symfony\Component\Console\Input\InputInterface;
	use Symfony\Component\Console\Input\InputOption;
	use Symfony\Component\Console\Output\OutputInterface;
	use Symfony\Component\Console\SingleCommandApplication;
	
	(new SingleCommandApplication())
		->setName('Simple Commandline Mail Backup')
		->setVersion('1.0.0')
		->addArgument('onlycount', InputArgument::OPTIONAL, 'Only count mailboxes')
		->setCode(function (InputInterface $input, OutputInterface $output) {
			
			// base functions
			function getRef($host,$port,$ssl){
				return "{".$host.":".$port."/imap/ssl".(($ssl)?"":"/novalidate-cert")."}";
			}
			function BoxConnect($output,$host,$port,$path,$user,$pass,$ssl){
				if(!($box = imap_open(getRef($host,$port,$ssl).$path, $user, $pass))){
					$output->writeln("<error>Error: ".imap_last_error()."</error>");
					die;
				}else{
					$output->writeln("<info>Connected to: $user @ $host</info>",OutputInterface::VERBOSITY_DEBUG);
					return $box;
				}
			}
			
			
			// base variables
			$configFile = 'config.json';
			$requiredConfigs = ['sourceHost', 'sourceUsername', 'sourcePassword', 'destinationHost', 'destinationUsername', 'destinationPassword'];
			$default = ['sourcePort'=>143,'destinationPort'=>143,'path'=>'INBOX','sourceCheckSSL'=>true,'destinationCheckSSL'=>true];
			
			$output->writeln("<info>Starting backup tool...</info>",OutputInterface::VERBOSITY_VERBOSE);
			if(!file_exists($configFile)){
				$output->writeln("<error>".$configFile." doesn't exist!</error>");
				die();
			}
			$config = json_decode(file_get_contents($configFile),true);
			if($config==NULL){
				$output->writeln("<error>Error in ".$configFile."</error>");
				die();
			}
			if (!empty(array_diff($requiredConfigs, array_keys($config)))) {
				$output->writeln("<error>Not all required keys are given.\nRequired keys:\n- ".implode("\n- ",$requiredConfigs)."</error>");
				die();
			}
			$output->writeln("<comment>Config file passed minimum configuration</comment>",OutputInterface::VERBOSITY_DEBUG);
			$config = array_merge($default,$config);
			$output->writeln("<question>Config extended with default config:</question>\n<comment>".print_r($config,true)."</comment>",OutputInterface::VERBOSITY_DEBUG);
			$output->writeln("<info>Connecting to Mailbox...</info>",OutputInterface::VERBOSITY_VERBOSE);
			
			// connect to mailboxes
			$oldMailBox = BoxConnect($output,$config['sourceHost'],$config['sourcePort'],$config['path'],$config['sourceUsername'],$config['sourcePassword'],$config['sourceCheckSSL']);
			$newMailBox = BoxConnect($output,$config['destinationHost'],$config['destinationPort'],$config['path'],$config['destinationUsername'],$config['destinationPassword'],$config['destinationCheckSSL']);

			// count items in mailbox (not always accurate since archives and sometimes trash isn't counted)
			$oldcount = imap_num_msg($oldMailBox);
			$newcount = imap_num_msg($newMailBox);
			$output->writeln("<info> Old mailbox:".$oldcount."; New mailbox:".$newcount."</info>",OutputInterface::VERBOSITY_VERBOSE);
			
			if($input->getArgument('onlycount')!==NULL)
				die();
			
			// get all folders
			$output->writeln("<info>Gathering old folders...</info>",OutputInterface::VERBOSITY_VERBOSE);
			$oldFolders = imap_listmailbox($oldMailBox, getRef($config['sourceHost'],$config['sourcePort'],$config['sourceCheckSSL']), "*");
			$newFolders = array_map(function($_box){$s = explode('}',$_box);return imap_utf7_decode(end($s));},imap_listmailbox($newMailBox, getRef($config['destinationHost'],$config['destinationPort'],$config['destinationCheckSSL']), "*"));
			
			$output->writeln("<info>Starting migration of ~".$oldcount." mails</info>", OutputInterface::VERBOSITY_VERBOSE);
			foreach($oldFolders as $_box){
				$folder = imap_utf7_decode(str_replace(getRef($config['sourceHost'],$config['sourcePort'],$config['sourceCheckSSL']),'',$_box));
				if(!in_array($folder,$newFolders)){
					if(@imap_createmailbox($newMailBox, imap_utf7_encode(getRef($config['destinationHost'],$config['destinationPort'],$config['destinationCheckSSL']).$folder)))
						$newFolders[] = $folder;
					else{
						$output->writeln("<error>Couldn't create \"".$folder."\" as folder! Please create it manually and re-run!</error>",OutputInterface::OUTPUT_NORMAL);
						die();
					}
				}
				imap_reopen($oldMailBox, $_box);
				$mailList = imap_search($oldMailBox, "ALL");
				foreach($mailList as $_mail){
					$pfx = "Msg #$_mail";
					$output->writeln("<info>Gather ".$pfx." from ".$folder.".</info>",OutputInterface::VERBOSITY_DEBUG);
					
					$fh = imap_fetchheader($oldMailBox, $_mail);
					$fb = imap_body($oldMailBox, $_mail);
					$message = $fh.$fb;
					
					if (!($ret = imap_append($newMailBox,getRef($config['destinationHost'],$config['destinationPort'],$config['destinationCheckSSL']).$folder,$message))) {
						$output->writeln("<error>Couldn't move message! Got message:</error>");
						$output->writeln("<error>".imap_last_error()."</error>");
						die;
					}else
						$output->writeln("<info>".$pfx." succesfully copied to ".$folder."</info>",OutputInterface::VERBOSITY_DEBUG);
				}
			}
			
			imap_close($oldMailBox);
			imap_close($newMailBox);
		})
		->run();