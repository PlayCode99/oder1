<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\StationDepartment;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Form 3 (กีฬาสี): artwork is attached per colour house, so what comes back out
 * has to be exactly what went in for that house — no more, no fewer, and never
 * another house's files.
 */
class SportsDayArtworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Artwork uploads would otherwise land in the real storage directory and
        // pile up as orphaned files every time the suite runs.
        Storage::fake('public');

        Carbon::setTestNow(Carbon::parse('2026-08-31 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'station_department' => StationDepartment::None,
        ]);
    }

    /**
     * @param  array<int|string, array<int, UploadedFile>>  $artwork
     * @return array<string, mixed>
     */
    private function payload(array $artwork): array
    {
        $customer = Customer::firstOrCreate(['customer_code' => 'CUS-SD-1'], ['customer_name' => 'โรงเรียนทดสอบ']);
        $branch = Branch::firstOrCreate(['branch_code' => 'BR-SD-1'], ['branch_name' => 'สาขากีฬาสี']);

        return [
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'branch_id' => $branch->id,
            'job_name' => 'เสื้อกีฬาสี',
            'job_type' => 'งานปัก',
            'order_date' => '2026-08-31 09:30:00',
            'due_date' => '2026-09-10 00:00:00',
            'discount_percent' => 0,
            'items' => [[
                'item_type' => 'garment', 'size_group' => 'adults', 'size_label' => 'M',
                'quantity' => 30, 'unit_price' => 200,
            ]],
            'specification' => [
                'pattern_id' => '1',
                'fabric_id' => '1',
                'screen_print_detail' => json_encode([
                    'schema' => 'spec-v2',
                    'mode' => 'sports_day',
                    'sports_day_groups' => [
                        ['team_name' => 'คณะสีแดง', 'rows' => []],
                        ['team_name' => 'คณะสีน้ำเงิน', 'rows' => []],
                    ],
                ]),
            ],
            'sports_day_artwork' => $artwork,
        ];
    }

    public function test_each_colour_house_keeps_exactly_the_files_it_was_given(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg'), UploadedFile::fake()->image('red-2.jpg'), UploadedFile::fake()->image('red-3.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();
        $grouped = $order->sports_day_artwork_urls;

        // 3 in -> 3 out, 1 in -> 1 out.
        $this->assertCount(3, $grouped['0']);
        $this->assertCount(1, $grouped['1']);
        $this->assertCount(4, $order->getMedia('sports_day_artwork'));
    }

    public function test_a_house_with_no_artwork_simply_has_none(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $grouped = Order::query()->firstOrFail()->sports_day_artwork_urls;

        $this->assertCount(1, $grouped['0']);
        $this->assertArrayNotHasKey('1', $grouped);
    }

    public function test_editing_adds_to_a_house_without_touching_the_other(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();

        $this->actingAs($this->admin())->put("/orders/{$order->id}", $this->payload([
            0 => [UploadedFile::fake()->image('red-2.jpg')],
        ]))->assertSessionHasNoErrors();

        $grouped = $order->fresh()->sports_day_artwork_urls;

        $this->assertCount(2, $grouped['0']);
        $this->assertCount(1, $grouped['1']);
    }

    public function test_the_endpoint_rejects_a_non_image_file(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->create('not-an-image.pdf', 10, 'application/pdf')],
        ]))->assertSessionHasErrors('sports_day_artwork.0.0');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_duplicating_carries_every_house_artwork_over(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg'), UploadedFile::fake()->image('red-2.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $source = Order::query()->firstOrFail();

        $duplicatePayload = $this->payload([]);
        $duplicatePayload['duplicate_from_id'] = $source->id;

        $this->actingAs($this->admin())->post('/orders', $duplicatePayload)->assertSessionHasNoErrors();

        $copy = Order::query()->whereKeyNot($source->id)->firstOrFail();
        $grouped = $copy->sports_day_artwork_urls;

        // The house tagging has to survive the copy, not just the file count.
        $this->assertCount(2, $grouped['0']);
        $this->assertCount(1, $grouped['1']);
        $this->assertCount(2, $source->fresh()->sports_day_artwork_urls['0']);
    }

    public function test_reopening_a_bill_hands_the_house_artwork_back_to_the_form(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('house-a.png')],
            1 => [UploadedFile::fake()->image('house-b1.png'), UploadedFile::fake()->image('house-b2.png')],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();

        // The edit form rebuilds each house from this payload. It used to be
        // missing, so every house came back with an empty gallery and the shop
        // saw its artwork as lost.
        $this->actingAs($this->admin())
            ->get("/orders/{$order->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Create')
                ->has('order.sports_day_artwork_urls.0', 1)
                ->has('order.sports_day_artwork_urls.1', 2)
            );
    }

    public function test_opening_the_bill_again_shows_the_artwork_before_it_is_saved(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('house-a.png')],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->actingAs($this->admin())
            ->get("/orders/{$order->id}/duplicate")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Create')
                ->has('order.sports_day_artwork_urls.0', 1)
            );
    }

    /**
     * A house's pictures are filed by its position. Removing the first house
     * moves the second up, and its pictures must move with it — they used to
     * stay behind, so the second house printed the removed house's pictures.
     */
    public function test_removing_a_house_takes_its_pictures_and_moves_the_next_house_s_up(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg'), UploadedFile::fake()->image('blue-2.jpg')],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->firstOrFail();
        $red = $order->getMedia('sports_day_artwork')->firstWhere('name', 'red-1');
        $blue = $order->getMedia('sports_day_artwork')->where('name', '!=', 'red-1')->values();

        $payload = $this->payload([]);
        $payload['specification']['screen_print_detail'] = json_encode([
            'schema' => 'spec-v2',
            'mode' => 'sports_day',
            'sports_day_groups' => [['team_name' => 'คณะสีน้ำเงิน', 'rows' => []]],
        ]);
        $payload['removed_media_ids'] = [$red->id];
        $payload['sports_day_artwork_houses'] = [$blue[0]->id => 0, $blue[1]->id => 0];

        $this->actingAs($this->admin())->put("/orders/{$order->id}", $payload)->assertSessionHasNoErrors();

        $media = $order->fresh()->getMedia('sports_day_artwork');

        $this->assertCount(2, $media);
        $this->assertNull($media->firstWhere('name', 'red-1'));
        $this->assertSame([0, 0], $media->map(fn ($m) => $m->getCustomProperty('sports_day_group'))->values()->all());
        $this->assertArrayNotHasKey('1', $order->fresh()->sports_day_artwork_urls);
    }

    public function test_opening_the_bill_again_without_a_house_moves_the_rest_up_on_the_copy_only(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $source = Order::query()->firstOrFail();
        $red = $source->getMedia('sports_day_artwork')->firstWhere('name', 'red-1');
        $blue = $source->getMedia('sports_day_artwork')->firstWhere('name', 'blue-1');

        $payload = $this->payload([]);
        $payload['duplicate_from_id'] = $source->id;
        $payload['removed_media_ids'] = [$red->id];
        $payload['sports_day_artwork_houses'] = [$blue->id => 0];

        $this->actingAs($this->admin())->post('/orders', $payload)->assertSessionHasNoErrors();

        $copy = Order::query()->whereKeyNot($source->id)->firstOrFail();
        $this->assertCount(1, $copy->sports_day_artwork_urls['0']);
        $this->assertSame('blue-1', $copy->getMedia('sports_day_artwork')->sole()->name);

        // The bill it was copied from is untouched.
        $this->assertSame(
            ['red-1' => 0, 'blue-1' => 1],
            $source->fresh()->getMedia('sports_day_artwork')
                ->mapWithKeys(fn ($m) => [$m->name => $m->getCustomProperty('sports_day_group')])->all(),
        );
    }

    public function test_a_house_position_out_of_range_is_refused(): void
    {
        $payload = $this->payload([]);
        $payload['sports_day_artwork_houses'] = [1 => 500];

        $this->actingAs($this->admin())->post('/orders', $payload)
            ->assertSessionHasErrors('sports_day_artwork_houses.1');
    }

    public function test_the_overview_names_each_house_s_pictures_once(): void
    {
        $this->actingAs($this->admin())->post('/orders', $this->payload([
            0 => [UploadedFile::fake()->image('red-1.jpg')],
            1 => [UploadedFile::fake()->image('blue-1.jpg')],
        ]))->assertSessionHasNoErrors();

        $gallery = Order::query()->firstOrFail()->artwork_gallery;

        $this->assertCount(2, $gallery);
        $this->assertSame([['sports_day_0'], ['sports_day_1']], array_column($gallery, 'places'));
    }

    public function test_the_counter_is_given_the_colour_picked_for_each_house(): void
    {
        $payload = $this->payload([]);
        $row = ['size_group' => 'adults', 'size_label' => 'M', 'shirt_qty' => 5, 'shirt_price' => 200, 'pants_qty' => 0, 'pants_price' => 0];
        $payload['specification']['screen_print_detail'] = json_encode([
            'schema' => 'spec-v2',
            'mode' => 'sports_day',
            'sports_day_groups' => [
                ['team_name' => 'คณะสีแดง', 'display_color' => '#dc2626', 'rows' => [$row]],
                ['team_name' => 'คณะสีฟ้า', 'display_color' => 'red;}', 'rows' => [$row]],
                ['team_name' => 'คณะสีเขียว', 'rows' => [$row]],
            ],
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->post('/orders', $payload)->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/counter')
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.details.sports_day_groups.0.display_color', '#DC2626')
                // Not a colour: dropped, so the house keeps the colour read from its name.
                ->where('orders.0.details.sports_day_groups.1.display_color', null)
                ->where('orders.0.details.sports_day_groups.2.display_color', null));
    }
}
