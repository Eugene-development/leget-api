<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\University\MediaStorage;
use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UniversityMediaTest extends TestCase
{
    use RefreshDatabase;

    private int $anonymousStatus = 403;

    private MediaStorage $storage;

    private $client;

    private object $row;

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_23_140000_create_university_tables.php')->up();
        (require __DIR__.'/../../../leget-db/database/migrations/2026_09_27_160000_extend_university_content.php')->up();
        $user = User::create(['name' => 'Test', 'email' => 'test@example.test', 'password' => 'password']);
        $this->client = Mockery::mock(S3ClientInterface::class);
        $this->storage = Mockery::mock(MediaStorage::class)->makePartial();
        $this->storage->shouldReceive('client')->andReturn($this->client);
        $id = (string) Str::uuid();
        DB::table('university_media')->insert(['id' => $id, 'created_by' => $user->id, 'object_key' => 'university/private/'.$id.'.pdf', 'filename' => 'guide.pdf', 'mime' => 'application/pdf', 'size' => 12, 'upload_id' => 'multipart-1', 'status' => 'uploading', 'created_at' => now(), 'updated_at' => now()]);
        $this->row = DB::table('university_media')->where('id', $id)->first();
        Http::fake(fn () => Http::response('', $this->anonymousStatus));
    }

    private function validObject(): void
    {
        $this->client->shouldReceive('headObject')->andReturn(new Result(['ContentLength' => 12, 'ContentType' => 'application/pdf']));
        $this->client->shouldReceive('getObject')->andReturn(new Result(['Body' => "%PDF-1.7\nEOF"]));
    }

    public function test_completion_is_validated_and_idempotent(): void
    {
        $this->client->shouldReceive('listParts')->once()->andReturn(new Result(['Parts' => [['PartNumber' => 1, 'Size' => 12, 'ETag' => 'etag']]]));
        $this->client->shouldReceive('completeMultipartUpload')->once()->andReturn(new Result);
        $this->validObject();
        $this->assertSame('ready', $this->storage->complete($this->row)['status']);
        $this->assertSame('ready', $this->storage->complete($this->row)['status']);
    }

    public function test_missing_parts_never_publish_a_file(): void
    {
        $this->client->shouldReceive('listParts')->once()->andReturn(new Result(['Parts' => []]));
        try {
            $this->storage->complete($this->row);
            $this->fail('Expected incomplete upload to fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('university_media', ['id' => $this->row->id, 'status' => 'uploading']);
    }

    public function test_public_bucket_policy_blocks_completion(): void
    {
        $this->client->shouldReceive('listParts')->andReturn(new Result(['Parts' => [['PartNumber' => 1, 'Size' => 12, 'ETag' => 'etag']]]));
        $this->client->shouldReceive('completeMultipartUpload')->andReturn(new Result);
        $this->validObject();
        $this->anonymousStatus = 200;
        try {
            $this->storage->complete($this->row);
            $this->fail('Expected public object to fail');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('university_media', ['id' => $this->row->id, 'status' => 'ready']);
    }

    public function test_lost_completion_response_can_be_retried(): void
    {
        $this->client->shouldReceive('listParts')->andThrow(new S3Exception('gone', new Command('ListParts'), ['code' => 'NoSuchUpload']));
        $this->validObject();
        $this->assertSame('ready', $this->storage->complete($this->row)['status']);
    }

    public function test_cancellation_is_idempotent_and_parts_outside_file_are_rejected(): void
    {
        try {
            $this->storage->part($this->row, 2);
            $this->fail('Expected part validation');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->client->shouldReceive('abortMultipartUpload')->once()->andReturn(new Result);
        $this->storage->abort($this->row);
        $this->storage->abort($this->row);
        $this->assertDatabaseHas('university_media', ['id' => $this->row->id, 'status' => 'cancelled']);
    }
}
