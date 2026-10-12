<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial WhistleCheck schema (wchk_* logical names ≤27 chars).
 *
 * Privacy-critical design notes (spec §9):
 *  - no reporter identity columns anywhere (no IP, UA, session, account)
 *  - lookup_hint is sha256(code) — a public fingerprint of a 128-bit code
 *  - code_hash is argon2id(code, code_salt) computed CLIENT-side; the server
 *    never sees the raw code in the JS path
 *  - all content columns hold opaque ciphertext envelopes only
 */
class Version1000Date202610120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('wchk_cases')) {
			$t = $schema->createTable('wchk_cases');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('lookup_hint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('code_hash', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('code_salt', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('key_salt', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('reporter_wrap', 'text', ['notnull' => true]);
			$t->addColumn('title_cipher', 'text', ['notnull' => false]);
			$t->addColumn('contact_cipher', 'text', ['notnull' => false]);
			$t->addColumn('category_id', 'bigint', ['notnull' => false]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 20, 'default' => 'received']);
			$t->addColumn('legal_hold', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('hold_reason', 'string', ['notnull' => false, 'length' => 255]);
			$t->addColumn('hold_set_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('assigned_to', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('received_at', 'datetime', ['notnull' => true]);
			$t->addColumn('feedback_due', 'date', ['notnull' => true]);
			$t->addColumn('feedback_sent_at', 'datetime', ['notnull' => false]);
			$t->addColumn('reminder_sent_at', 'datetime', ['notnull' => false]);
			$t->addColumn('overdue_notified_at', 'datetime', ['notnull' => false]);
			$t->addColumn('close_requested_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('closed_at', 'datetime', ['notnull' => false]);
			$t->addColumn('closed_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('retention_until', 'date', ['notnull' => false]);
			$t->addColumn('retention_warned', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_e2e', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'wchk_cases_pk');
			$t->addUniqueIndex(['lookup_hint'], 'wchk_cases_hint_uq');
			$t->addIndex(['status'], 'wchk_cases_status_idx');
			$t->addIndex(['feedback_due'], 'wchk_cases_due_idx');
			$t->addIndex(['retention_until'], 'wchk_cases_ret_idx');
			$t->addIndex(['assigned_to'], 'wchk_cases_assign_idx');
		}

		if (!$schema->hasTable('wchk_case_keys')) {
			$t = $schema->createTable('wchk_case_keys');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('officer_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('wrapped_key', 'text', ['notnull' => true]);
			$t->addColumn('granted_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('granted_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_ckeys_pk');
			$t->addUniqueIndex(['case_id', 'officer_uid'], 'wchk_ckeys_co_uq');
			$t->addIndex(['officer_uid'], 'wchk_ckeys_uid_idx');
		}

		if (!$schema->hasTable('wchk_messages')) {
			$t = $schema->createTable('wchk_messages');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('direction', 'string', ['notnull' => true, 'length' => 16]);
			$t->addColumn('cipher', 'text', ['notnull' => true]);
			$t->addColumn('author_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_msgs_pk');
			$t->addIndex(['case_id'], 'wchk_msgs_case_idx');
		}

		if (!$schema->hasTable('wchk_attachments')) {
			$t = $schema->createTable('wchk_attachments');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('message_id', 'bigint', ['notnull' => false]);
			$t->addColumn('cipher', 'text', ['notnull' => true]);
			$t->addColumn('name_cipher', 'text', ['notnull' => false]);
			$t->addColumn('size', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('mime_claimed', 'string', ['notnull' => true, 'length' => 128, 'default' => 'application/octet-stream']);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_att_pk');
			$t->addIndex(['case_id'], 'wchk_att_case_idx');
			$t->addIndex(['message_id'], 'wchk_att_msg_idx');
		}

		if (!$schema->hasTable('wchk_notes')) {
			$t = $schema->createTable('wchk_notes');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('cipher', 'text', ['notnull' => true]);
			$t->addColumn('author_uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_notes_pk');
			$t->addIndex(['case_id'], 'wchk_notes_case_idx');
		}

		if (!$schema->hasTable('wchk_officers')) {
			$t = $schema->createTable('wchk_officers');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('pubkey', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('fingerprint', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$t->addColumn('key_version', 'integer', ['notnull' => true, 'default' => 1]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('revoked_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revoked_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id'], 'wchk_officers_pk');
			$t->addUniqueIndex(['uid'], 'wchk_officers_uid_uq');
			$t->addIndex(['status'], 'wchk_officers_st_idx');
		}

		if (!$schema->hasTable('wchk_audit')) {
			$t = $schema->createTable('wchk_audit');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('seq', 'bigint', ['notnull' => true]);
			$t->addColumn('prev_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('event_type', 'string', ['notnull' => true, 'length' => 48]);
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('case_id', 'bigint', ['notnull' => false]);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_audit_pk');
			$t->addUniqueIndex(['seq'], 'wchk_audit_seq_uq');
			$t->addIndex(['case_id'], 'wchk_audit_case_idx');
			$t->addIndex(['event_type'], 'wchk_audit_type_idx');
		}

		if (!$schema->hasTable('wchk_rate')) {
			$t = $schema->createTable('wchk_rate');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('bucket_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 24]);
			$t->addColumn('hits', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('expires_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_rate_pk');
			$t->addUniqueIndex(['bucket_hash', 'kind'], 'wchk_rate_bk_uq');
			$t->addIndex(['expires_at'], 'wchk_rate_exp_idx');
		}

		if (!$schema->hasTable('wchk_config')) {
			$t = $schema->createTable('wchk_config');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ckey', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('cvalue', 'text', ['notnull' => false]);
			$t->addColumn('updated_by', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'wchk_config_pk');
			$t->addUniqueIndex(['ckey'], 'wchk_config_key_uq');
		}

		if (!$schema->hasTable('wchk_categories')) {
			$t = $schema->createTable('wchk_categories');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('label_json', 'text', ['notnull' => true]);
			$t->addColumn('archived', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sort', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'wchk_cat_pk');
		}

		if (!$schema->hasTable('wchk_idem')) {
			$t = $schema->createTable('wchk_idem');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ikey', 'string', ['notnull' => true, 'length' => 128]);
			$t->addColumn('case_id', 'bigint', ['notnull' => true]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'wchk_idem_pk');
			$t->addUniqueIndex(['ikey'], 'wchk_idem_key_uq');
		}

		if (!$schema->hasTable('wchk_form_tokens')) {
			$t = $schema->createTable('wchk_form_tokens');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('token', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('bucket_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('issued_at', 'datetime', ['notnull' => true]);
			$t->addColumn('min_submit_at', 'datetime', ['notnull' => true]);
			$t->addColumn('used', 'integer', ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id'], 'wchk_ftok_pk');
			$t->addUniqueIndex(['token'], 'wchk_ftok_tok_uq');
			$t->addIndex(['issued_at'], 'wchk_ftok_issued_idx');
		}

		return $schema;
	}
}
