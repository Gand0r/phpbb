<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\console\command\user;

use phpbb\config\config;
use phpbb\console\command\command;
use phpbb\db\driver\driver_interface;
use phpbb\exception\runtime_exception;
use phpbb\language\language;
use phpbb\messenger\method\email;
use phpbb\passwords\manager;
use phpbb\user;
use Symfony\Component\Console\Command\Command as symfony_command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class add extends command
{
	/** @var array Array of interactively acquired options */
	protected $data;

	/** @var driver_interface */
	protected $db;

	/** @var config */
	protected $config;

	/** @var email */
	protected $email_method;

	/** @var language */
	protected $language;

	/** @var manager */
	protected $password_manager;

	/**
	 * phpBB root path
	 *
	 * @var string
	 */
	protected $phpbb_root_path;

	/**
	 * PHP extension.
	 *
	 * @var string
	 */
	protected $php_ext;

	/**
	 * Construct method
	 *
	 * @param user             $user
	 * @param driver_interface $db
	 * @param config           $config
	 * @param language         $language
	 * @param service_collection $messenger
	 * @param manager          $password_manager
	 * @param string           $phpbb_root_path
	 * @param string           $php_ext
	 */
	public function __construct(user $user, driver_interface $db, config $config, language $language, email $email_method, manager $password_manager, $phpbb_root_path, $php_ext)
	{
		$this->config = $config;
		$this->db = $db;
		$this->email_method = $email_method;
		$this->language = $language;
		$this->password_manager = $password_manager;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;

		$this->language->add_lang('ucp');
		parent::__construct($user);
	}

	/**
	 * Sets the command name and description
	 *
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('user:add')
			->setDescription($this->language->lang('CLI_DESCRIPTION_USER_ADD'))
			->setHelp($this->language->lang('CLI_HELP_USER_ADD'))
			->addOption(
				'username',
				'U',
				InputOption::VALUE_REQUIRED,
				$this->language->lang('CLI_DESCRIPTION_USER_ADD_OPTION_USERNAME')
			)
			->addOption(
				'password',
				'P',
				InputOption::VALUE_REQUIRED,
				$this->language->lang('CLI_DESCRIPTION_USER_ADD_OPTION_PASSWORD')
			)
			->addOption(
				'email',
				'E',
				InputOption::VALUE_REQUIRED,
				$this->language->lang('CLI_DESCRIPTION_USER_ADD_OPTION_EMAIL')
			)
			->addOption(
				'send-email',
				null,
				InputOption::VALUE_NONE,
				$this->language->lang('CLI_DESCRIPTION_USER_ADD_OPTION_NOTIFY')
			)
		;
	}

	/**
	 * Executes the command user:add
	 *
	 * Adds a new user to the database. If options are not provided, it will ask for the username, password and email.
	 * User is added to the registered user group. Language and timezone default to $config settings.
	 *
	 * @param InputInterface  $input  The input stream used to get the options
	 * @param OutputInterface $output The output stream, used to print messages
	 *
	 * @return int 0 if all is well, 1 if any errors occurred
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$io = new SymfonyStyle($input, $output);

		try
		{
			$this->validate_user_data();
			$group_id = $this->get_group_id();
		}
		catch (runtime_exception $e)
		{
			$io->error($e->getMessage());
			return symfony_command::FAILURE;
		}

		$user_row = array(
			'username'      => $this->data['username'],
			'user_password' => $this->password_manager->hash($this->data['new_password']),
			'user_email'    => $this->data['email'],
			'group_id'      => $group_id,
			'user_timezone' => $this->config['board_timezone'],
			'user_lang'     => $this->config['default_lang'],
			'user_type'     => USER_NORMAL,
			'user_regdate'  => time(),
		);

		$user_id = (int) user_add($user_row);

		if (!$user_id)
		{
			$io->error($this->language->lang('AUTH_NO_PROFILE_CREATED'));
			return symfony_command::FAILURE;
		}

		if ($input->getOption('send-email') && $this->config['email_enable'])
		{
			$this->send_activation_email($user_id);
		}

		$io->success($this->language->lang('CLI_USER_ADD_SUCCESS', $this->data['username']));

		return symfony_command::SUCCESS;
	}

	/**
	 * Interacts with the user.
	 *
	 * @param InputInterface  $input  An InputInterface instance
	 * @param OutputInterface $output An OutputInterface instance
	 */
	protected function interact(InputInterface $input, OutputInterface $output)
	{
		$helper = $this->getHelper('question');
		if (!$helper instanceof QuestionHelper)
		{
			return;
		}

		$this->data = array(
			'username'     => $input->getOption('username'),
			'new_password' => $input->getOption('password'),
			'email'        => $input->getOption('email'),
		);

		if (!$this->data['username'])
		{
			$question = new Question($this->ask_user('USERNAME'));
			$this->data['username'] = $helper->ask($input, $output, $question);
		}

		if (!$this->data['new_password'])
		{
			$question = new Question($this->ask_user('PASSWORD'));
			$question->setValidator(function ($value) use ($helper, $input, $output) {
				$question = new Question($this->ask_user('CONFIRM_PASSWORD'));
				$question->setHidden(true);
				if ($helper->ask($input, $output, $question) != $value)
				{
					throw new runtime_exception($this->language->lang('NEW_PASSWORD_ERROR'));
				}
				return $value;
			});
			$question->setHidden(true);
			$question->setMaxAttempts(5);

			$this->data['new_password'] = $helper->ask($input, $output, $question);
		}

		if (!$this->data['email'])
		{
			$question = new Question($this->ask_user('EMAIL_ADDRESS'));
			$this->data['email'] = $helper->ask($input, $output, $question);
		}
	}

	/**
	 * Validate the submitted user data
	 *
	 * @throws runtime_exception if any data fails validation
	 * @return void
	 */
	protected function validate_user_data()
	{
		if (!function_exists('validate_data'))
		{
			require($this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext);
		}

		$error = validate_data($this->data, array(
			'username'     => array(
				array('string', false, $this->config['min_name_chars'], $this->config['max_name_chars']),
				array('username', '')),
			'new_password' => array(
				array('string', false, $this->config['min_pass_chars'], 0),
				array('password')),
			'email'        => array(
				array('string', false, 6, 60),
				array('user_email')),
		));

		if ($error)
		{
			throw new runtime_exception(implode("\n", array_map(array($this->language, 'lang'), $error)));
		}
	}

	/**
	 * Get the group id
	 *
	 * Go and find in the database the group_id corresponding to 'REGISTERED'
	 *
	 * @throws runtime_exception if the group id does not exist in database.
	 * @return null
	 */
	protected function get_group_id()
	{
		$sql = 'SELECT group_id
			FROM ' . GROUPS_TABLE . "
			WHERE group_name = '" . $this->db->sql_escape('REGISTERED') . "'
				AND group_type = " . GROUP_SPECIAL;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row || !$row['group_id'])
		{
			throw new runtime_exception($this->language->lang('NO_GROUP'));
		}

		return $row['group_id'];
	}

	/**
	 * Send account activation email
	 *
	 * @param int   $user_id The new user's id
	 * @return void
	 */
	protected function send_activation_email($user_id)
	{
		switch ($this->config['require_activation'])
		{
			case USER_ACTIVATION_SELF:
				$email_template = 'user_welcome_inactive';
			break;
			case USER_ACTIVATION_ADMIN:
				$email_template = 'admin_welcome_inactive';
			break;
			default:
				$email_template = 'user_welcome';
			break;
		}

		$user_actkey = $this->get_activation_key($user_id);

		$this->email_method->set_use_queue(false);
		$this->email_method->template($email_template, $this->user->lang_name);
		$this->email_method->to($this->data['email'], $this->data['username']);
		$this->email_method->anti_abuse_headers($this->config, $this->user);
		$this->email_method->assign_vars([
			'WELCOME_MSG' => html_entity_decode($this->language->lang('WELCOME_SUBJECT', $this->config['sitename']), ENT_COMPAT),
			'USERNAME'    => html_entity_decode($this->data['username'], ENT_COMPAT),
			'PASSWORD'    => html_entity_decode($this->data['new_password'], ENT_COMPAT),
			'U_ACTIVATE'  => generate_board_url() . "/ucp.{$this->php_ext}?mode=activate&u=$user_id&k=$user_actkey",
		]);
		$this->email_method->send();
	}

	/**
	 * Get user activation key
	 *
	 * @param int $user_id User ID
	 *
	 * @return string User activation key for user
	 */
	protected function get_activation_key(int $user_id): string
	{
		$user_actkey = '';

		if ($this->config['require_activation'] == USER_ACTIVATION_SELF || $this->config['require_activation'] == USER_ACTIVATION_ADMIN)
		{
			$user_actkey = gen_rand_string(mt_rand(6, 10));

			$sql_ary = [
				'user_actkey'				=> $user_actkey,
				'user_actkey_expiration'	=> user::get_token_expiration(),
			];

			$sql = 'UPDATE ' . USERS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE user_id = ' . (int) $user_id;
			$this->db->sql_query($sql);
		}

		return $user_actkey;
	}

	/**
	 * Helper to translate questions to the user
	 *
	 * @param string $key The language key
	 * @return string The language key translated with a colon and space appended
	 */
	protected function ask_user($key)
	{
		return $this->language->lang($key) . $this->language->lang('COLON') . ' ';
	}
}
