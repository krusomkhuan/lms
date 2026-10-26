<?php
// เรียก Claude Messages API ผ่าน Anthropic PHP SDK (anthropic-ai/sdk) — ต้องใช้ PHP 8.1+
// โหลดเฉพาะเมื่อ ai_unavailable_reason() === null (ดู includes/ai.php)

require_once ROOT_PATH . '/vendor/autoload.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * @param array  $messages [['role' => 'user'|'assistant', 'content' => string], ...]
 * @param string $effort   low|medium|high (ระดับความละเอียดในการคิด)
 * @return array status (ok|refusal|error), text, model, input_tokens, output_tokens, cache_read_tokens, error
 */
function ai_client_complete(string $apiKey, string $model, string $system, array $messages, string $effort, int $maxTokens): array
{
    $client = new Client(apiKey: $apiKey);
    $params = [
        'model' => $model,
        'maxTokens' => $maxTokens,
        // system prompt (คำสั่ง + เนื้อหารายวิชา) คงที่ระหว่างคำถามในบทเรียนเดียวกัน → แคชไว้ลดค่าใช้จ่าย
        'system' => [
            ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
        ],
        'messages' => $messages,
        'outputConfig' => ['effort' => $effort],
    ];

    try {
        if ($model === 'claude-haiku-5-5') {
            // Haiku 5.5 ไม่มี server-side fallback
            $message = $client->messages->create(...$params);
        } else {
            // ถ้าระบบความปลอดภัยของโมเดลปฏิเสธ ให้ API ลองโมเดลสำรองที่ Anthropic แนะนำให้อัตโนมัติ
            $message = $client->beta->messages->create(...array_merge($params, [
                'fallbacks' => 'default',
                'betas' => ['server-side-fallback-2026-07-01'],
            ]));
        }
    } catch (AuthenticationException $e) {
        return ['status' => 'error', 'error' => 'API key ไม่ถูกต้อง (ตรวจสอบที่ หลังบ้าน → ตั้งค่า AI)', 'model' => $model];
    } catch (PermissionDeniedException $e) {
        return ['status' => 'error', 'error' => 'API key นี้ไม่มีสิทธิ์ใช้โมเดล ' . $model, 'model' => $model];
    } catch (NotFoundException $e) {
        return ['status' => 'error', 'error' => 'ไม่พบโมเดล ' . $model, 'model' => $model];
    } catch (RateLimitException $e) {
        return ['status' => 'error', 'error' => 'มีผู้ใช้ AI พร้อมกันมากเกินไป กรุณารอสักครู่แล้วลองใหม่', 'model' => $model];
    } catch (BadRequestException $e) {
        return ['status' => 'error', 'error' => 'คำขอไม่ถูกต้อง: ' . $e->getMessage(), 'model' => $model];
    } catch (APIStatusException $e) {
        $msg = ($e->type?->value ?? '') === 'billing_error' ? 'เครดิต Claude API ไม่พอ (ติดต่อผู้ดูแลระบบ)' : 'บริการ AI ขัดข้องชั่วคราว ลองใหม่อีกครั้ง';
        return ['status' => 'error', 'error' => $msg, 'model' => $model];
    } catch (APIConnectionException $e) {
        return ['status' => 'error', 'error' => 'เชื่อมต่อบริการ AI ไม่ได้ (ตรวจสอบอินเทอร์เน็ตของเซิร์ฟเวอร์)', 'model' => $model];
    }

    $usage = [
        'model' => (string)($message->model ?? $model),
        'input_tokens' => (int)($message->usage->inputTokens ?? 0),
        'output_tokens' => (int)($message->usage->outputTokens ?? 0),
        'cache_read_tokens' => (int)($message->usage->cacheReadInputTokens ?? 0),
    ];

    // ตรวจ stop reason ก่อนอ่านเนื้อหาเสมอ
    if ($message->stopReason === 'refusal') {
        return $usage + ['status' => 'refusal', 'text' => ''];
    }
    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    if ($message->stopReason === 'max_tokens') {
        $text .= '<p><i>(คำตอบยาวเกินกำหนด ถูกตัดท้าย)</i></p>';
    }
    return $usage + ['status' => 'ok', 'text' => $text];
}
