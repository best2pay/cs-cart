<?php

defined('BOOTSTRAP') or die('Access denied');

const BEST2PAY_PROCESSOR = 'best2pay.php';

const BEST2PAY_SUPPORTED_TYPES = [
	'PURCHASE',
	'PURCHASE_BY_QR',
	'AUTHORIZE',
	'REVERSE',
	'COMPLETE'
];