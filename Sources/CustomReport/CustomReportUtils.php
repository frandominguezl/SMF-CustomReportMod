<?php

/**
* @package manifest file for Custom Report Mod
* @version 1.4
* @author Joker (http://www.simplemachines.org/community/index.php?action=profile;u=226111)
* @copyright Copyright (c) 2014, Siddhartha Gupta
* @license http://www.mozilla.org/MPL/MPL-1.1.html
*/

/*
* Version: MPL 1.1
*
* The contents of this file are subject to the Mozilla Public License Version
* 1.1 (the "License"); you may not use this file except in compliance with
* the License. You may obtain a copy of the License at
* http://www.mozilla.org/MPL/
*
* Software distributed under the License is distributed on an "AS IS" basis,
* WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
* for the specific language governing rights and limitations under the
* License.
*
* The Initial Developer of the Original Code is
*  Joker (http://www.simplemachines.org/community/index.php?action=profile;u=226111)
* Portions created by the Initial Developer are Copyright (C) 2012
* the Initial Developer. All Rights Reserved.
*
* Contributor(s):
*
*/

if (!defined('SMF'))
	die('Hacking attempt...');

class CustomReportUtils {
	private $dbInstance;

	public function __construct() {
		$this->dbInstance = null;
	}

	public function checkSolveStatus($topicId) {
		global $txt, $context, $modSettings;

		if($context['current_board'] !== $modSettings['report_board_id'] || !$this->isAllowedTo()) {
			$data = array(
				'showButton' => false,
			);
			return $data;
		}

		// Load the class if only required
		CustomReport::loadClass('CustomReportDB');
		$this->$dbInstance = new CustomReportDB();

		$isTopicSolved = $this->$dbInstance->checkIsTopicSolved($topicId);
		$data = array(
			'text' => empty($isTopicSolved['solved']) ? '[' . $txt['report_solved']. ']' : '[' . $txt['report_unsolved']. ']',
			'showButton' => true
		);
		return $data;
	}

	public function isAllowedTo() {
		global $user_info, $modSettings;

		if ($user_info['is_admin']) {
			return true;
		}

		$allowedGroups = explode(',', $modSettings['cr_can_solve_report']);
		$groupsPassed = array_intersect($allowedGroups, $user_info['groups']);

		if (empty($groupsPassed)) {
			return false;
		}
		return true;
	}

	public function CustomReportToModerator2() {
		global $txt, $scripturl, $topic, $board_info, $user_info, $modSettings, $sourcedir, $smcFunc, $context;

		// You must have the proper permissions!
		isAllowedTo('report_any');

		loadLanguage('Post');	

		// Make sure they aren't spamming.
		spamProtection('reporttm');

		require_once($sourcedir . '/Subs-Post.php');

		if(empty($modSettings['report_board_id']))
		fatal_lang_error('rtm_noboard');

		// No errors, yet.
		$post_errors = array();

		// Check their session.
		if (checkSession('post', '', false) != '')
		$post_errors[] = 'session_timeout';	

		// Make sure we have a comment and it's clean.
		if (!isset($_POST['comment']) || $smcFunc['htmltrim']($_POST['comment']) === '')
			$post_errors[] = 'no_comment';
		$poster_comment = $smcFunc['htmlspecialchars']($_POST['comment'], ENT_QUOTES);

		// Guests need to provide their name and email address!
		if ($user_info['is_guest'])
		{
			$_POST['guestname'] = !isset($_POST['guestname']) ? '' : trim($_POST['guestname']);
			$_POST['email'] = !isset($_POST['email']) ? '' : trim($_POST['email']);

			// Validate the name.
			if (!isset($_POST['guestname']) || trim(strtr($_POST['guestname'], '_', ' ')) == '')
				$post_errors[] = 'no_name';
			elseif ($smcFunc['strlen']($_POST['guestname']) > 25)
				$post_errors[] = 'long_name';
			else
			{
				require_once($sourcedir . '/Subs-Members.php');
				if (isReservedName(htmlspecialchars($_POST['guestname']), 0, true, false))
					$post_errors[] = 'bad_name';
			}

			// Validate the email.
			if ($_POST['email'] === '')
				$post_errors[] = 'no_email';
			elseif (preg_match('~^[0-9A-Za-z=_+\-/][0-9A-Za-z=_\'+\-/\.]*@[\w\-]+(\.[\w\-]+)*(\.[\w]{2,6})$~', $_POST['email']) == 0)
				$post_errors[] = 'bad_email';

			isBannedEmail($_POST['email'], 'cannot_post', sprintf($txt['you_are_post_banned'], $txt['guest_title']));

			$user_info['email'] = htmlspecialchars($_POST['email']);
		}

		// Could they get the right verification code?
		if ($user_info['is_guest'] && !empty($modSettings['guests_report_require_captcha']))
		{
			require_once($sourcedir . '/Subs-Editor.php');
			$verificationOptions = array(
				'id' => 'report',
			);
			$context['require_verification'] = create_control_verification($verificationOptions, true);
			if (is_array($context['require_verification']))
				$post_errors = array_merge($post_errors, $context['require_verification']);
		}

		// Any errors?
		if (!empty($post_errors))
		{
			loadLanguage('Errors');

			$context['post_errors'] = array();
			foreach ($post_errors as $post_error)
				$context['post_errors'][] = $txt['error_' . $post_error];

			return ReportToModerator();
		}

		// Get the basic topic information, and make sure they can see it.
		$_POST['msg'] = (int) $_POST['msg'];

		$request = $smcFunc['db_query']('', '
			SELECT m.id_topic, m.subject, m.body, m.id_member AS id_poster, m.poster_name, mem.real_name, m.poster_time
			FROM {db_prefix}messages AS m
				LEFT JOIN {db_prefix}members AS mem ON (m.id_member = mem.id_member)
			WHERE m.id_msg = {int:id_msg}
				AND m.id_topic = {int:current_topic}
			LIMIT 1',
			array(
				'current_topic' => $topic,
				'id_msg' => $_POST['msg'],
			)
		);
		if ($smcFunc['db_num_rows']($request) == 0)
			fatal_lang_error('no_board', false);
		$message = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		$request = $smcFunc['db_query']('', '
			SELECT c.id_report_topic, c.id_msg, c.id_topic
			FROM {db_prefix}custom_report_mod AS c
			WHERE c.id_msg = {int:id_msg}
			AND c.id_topic = {int:current_topic}
			LIMIT 1',
			array(
				'id_msg' => $_POST['msg'],
				'current_topic' => $topic,
			)
		);
		if ($smcFunc['db_num_rows']($request) > 0)
			list ($context['report_mod']['id_report_topic'], $context['report_mod']['id_msg'], $context['report_mod']['id_topic']) = $smcFunc['db_fetch_row']($request);
		$smcFunc['db_free_result']($request);

		// Get the poster and reporter names
		$poster_name = un_htmlspecialchars($message['real_name']);
		$reporterName = un_htmlspecialchars(!$user_info['is_guest'] ? $user_info['name'] : $_POST['guestname']);

		//Content for report post in the report board.
		$subject = $txt['reported_post'] . ' : ' . $message['subject'];

		$body = $txt['post_report_board'] . ' : ' . $reporterName . '<br /><br />' .
			$txt['post_made_by'] . ' : ' . $message['real_name'] . ' ' . $txt['at'] . ' ' . timeformat($message['poster_time']) . '<br /><br />' .
			(!empty($modSettings['quote_reported_post']) ? '[quote author=' . $poster_name . ' link=topic=' . $topic . '.msg' . $_POST['msg'] . '#msg' . $_POST['msg'] . ' date=' . $message['poster_time'] . ']' . "\n" . rtrim($message['body']) . "\n" . '[/quote]' :
			'<a href="'. $scripturl .  '?topic=' . $topic . '.msg' . $_POST['msg'] . '#msg' . $_POST['msg'] .'" target="_blank">' . $txt['post_link'] . '</a><br /><br />') .
			'<br />' . $txt['report_comment'] . ' : ' . '<br />' .
			$poster_comment;

		preparsecode($body);

		// set up all options
		$msgOptions = array(
			'id' => 0,
			'subject' => $subject,
			'body' => $body,
			'icon' => 'xx',
			'smileys_enabled' => true,
			'attachments' => array(),
			'approved' => true,
		);
		$topicOptions = array(
			'id' => empty($context['report_mod']['id_report_topic']) ? 0 : $context['report_mod']['id_report_topic'],
			'board' => $modSettings['report_board_id'],
			'poll' => null,
			'lock_mode' => 0,
			'sticky_mode' => null,
			'mark_as_read' => false,
			'is_approved' => true
		);
		$posterOptions = array(
			'id' => $user_info['id'],
			'name' => $reporterName,
			'email' => $user_info['email'],
			'update_post_count' => !$user_info['is_guest'] && !empty($modSettings['enable_report_count']) && $board_info['posts_count'],
		);

		// And at last make a post, yeyy :P!
		createPost($msgOptions, $topicOptions, $posterOptions);

		if(empty($context['report_mod']['id_msg']))
		{
			$smcFunc['db_insert']('',
			'{db_prefix}custom_report_mod',
				array(
					'id_report_topic' => 'int', 'id_msg' => 'int', 'id_topic' => 'int',
				),
				array(
					$topicOptions['id'], $_POST['msg'], $topic,
				),
			array('')
			);
		}
		// Opps someone is making a reply, quickly mark this as unsolved
		else
		{
			$request = $smcFunc['db_query']('', '
				UPDATE {db_prefix}custom_report_mod
				SET solved = {int:is_solved}
				WHERE id_report_topic = {int:topic}',
				array(
					'topic' => $context['report_mod']['id_report_topic'],
					'is_solved' => 0,
				)
			);
		}
		
		// Back to the post we reported!
		redirectexit('reportsent;topic=' . $topic . '.msg' . $_POST['msg'] . '#msg' . $_POST['msg']);
	}
}

?>
