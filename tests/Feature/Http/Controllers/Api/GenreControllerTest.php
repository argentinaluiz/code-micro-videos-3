<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Http\Controllers\Api\GenreController;
use App\Models\Category;
use App\Models\Genre;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Tests\Exceptions\TestException;
use Tests\TestCase;
use Tests\Traits\TestSaves;
use Tests\Traits\TestValidations;

class GenreControllerTest extends TestCase
{
    use DatabaseMigrations, TestValidations, TestSaves;

    private $sendData;
    private $genre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->genre = factory(Genre::class)->create();
        $this->sendData = [
            'name' => 'name',
            'is_active' => true
        ];
    }

    public function testIndex()
    {
        $response = $this->get(route('genres.index'));
        $response
            ->assertStatus(200)
            ->assertJson([$this->genre->toArray()]);
    }

    public function testShow()
    {
        $response = $this->get(route('genres.show', ['genre' => $this->genre->id]));
        $response
            ->assertStatus(200)
            ->assertJson($this->genre->toArray());
    }

    public function testInvalidationData()
    {
        $data = [
            'name' => '',
            'categories_id' => '',
        ];
        $this->assertInvalidationInStoreAction($data, 'required');
        $this->assertInvalidationInUpdateAction($data, 'required');

        $data = [
            'name' => str_repeat('a', 256),
        ];
        $this->assertInvalidationInStoreAction($data, 'max.string', ['max' => 255]);
        $this->assertInvalidationInUpdateAction($data, 'max.string', ['max' => 255]);

        $data = [
            'is_active' => 'a',
        ];
        $this->assertInvalidationInStoreAction($data, 'boolean');
        $this->assertInvalidationInUpdateAction($data, 'boolean');

        $data = [
            'categories_id' => 'a',
        ];
        $this->assertInvalidationInStoreAction($data, 'array');
        $this->assertInvalidationInUpdateAction($data, 'array');

        $data = [
            'categories_id' => [100],
        ];
        $this->assertInvalidationInStoreAction($data, 'exists');
        $this->assertInvalidationInUpdateAction($data, 'exists');

        $category = factory(Category::class)->create();
        $category->delete();
        $data = [
            'categories_id' => [$category->id],
        ];
        $this->assertInvalidationInStoreAction($data, 'exists');
        $this->assertInvalidationInUpdateAction($data, 'exists');
    }

    public function testSave()
    {
        $category = factory(Category::class)->create();
        $data = [
            [
                'send_data' => $this->sendData + [
                    'categories_id' => [$category->id],
                    'is_active' => true
                ],
                'test_data' => $this->sendData + ['is_active' => true,]
            ],
            [
                'send_data' => $this->sendData + [
                    'categories_id' => [$category->id],
                    'is_active' => false
                ],
                'test_data' => $this->sendData + ['is_active' => false]
            ]
        ];
        foreach ($data as $key => $value) {
            $response = $this->assertStore(
                $value['send_data'],
                $value['test_data'] + ['deleted_at' => null]
            );
            $response->assertJsonStructure([
                'created_at',
                'updated_at'
            ]);
            $this->assertHasCategory($response->json('id'), $category->id);

            $response = $this->assertUpdate(
                $value['send_data'],
                $value['test_data'] + ['deleted_at' => null]
            );
            $response->assertJsonStructure([
                'created_at',
                'updated_at'
            ]);
            $this->assertHasCategory($response->json('id'), $category->id);
        }
    }

    public function testRollBackStore()
    {
        $controller = \Mockery::mock(GenreController::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $controller
            ->shouldReceive('validate')
            ->withAnyArgs()
            ->andReturn($this->sendData);

        $controller
            ->shouldReceive('rulesStore')
            ->withAnyArgs()
            ->andReturn([]);

        $request = \Mockery::mock(Request::class);

        $controller
            ->shouldReceive('handleRelations')
            ->once()
            ->andThrow(new TestException());

        $hasError = false;
        try {
            $controller->store($request);
        } catch (TestException $exception) {
            $this->assertCount(1, Genre::all());
            $hasError = true;
        }
        $this->assertTrue($hasError);
    }

    public function testRollBackUpdate()
    {
        $controller = \Mockery::mock(GenreController::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $controller
            ->shouldReceive('findOrFail')
            ->withAnyArgs()
            ->andReturn(
                $this->genre
            );

        $controller
            ->shouldReceive('validate')
            ->withAnyArgs()
            ->andReturn(
                ['name' => 'test']
            );

        $controller
            ->shouldReceive('rulesUpdate')
            ->withAnyArgs()
            ->andReturn([]);

        $controller
            ->shouldReceive('handleRelations')
            ->once()
            ->andThrow(new TestException());

        $request = \Mockery::mock(Request::class);

        $hasError = false;
        try {
            $controller->update($request, 1);
        } catch (TestException $exception) {
            $this->assertCount(1, Genre::all());
            $hasError = true;
        }
        $this->assertTrue($hasError);
    }

    public function testSyncCategories()
    {
        $categoriesId = factory(Category::class, 3)->create()->pluck('id')->toArray();

        $sendData = [
            'name' => 'test',
            'categories_id'   => [$categoriesId[0]]
        ];

        $response = $this->json('POST', $this->routeStore(), $sendData);

        $this->assertHasCategory($response->json('id'), $categoriesId[0]);

        $sendData = [
            'name' => 'test',
            'categories_id'   => [$categoriesId[1], $categoriesId[2]]
        ];

        $response = $this->json(
            'PUT',
            route('genres.update', ['genre' => $response->json('id')]),
            $sendData
        );

        $this->assertDatabaseMissingHasCategory($response->json('id'), $categoriesId[0]);
        $this->assertHasCategory($response->json('id'), $categoriesId[1]);
        $this->assertHasCategory($response->json('id'), $categoriesId[2]);
    }

    protected function assertHasCategory($genreId, $categoryId)
    {
        $this->assertDatabaseHas('category_genre', ['genre_id' => $genreId, 'category_id' => $categoryId]);
    }

    public function assertDatabaseMissingHasCategory($genreId, $categoryId)
    {
        $this->assertDatabaseMissing('category_genre', ['genre_id' => $genreId, 'category_id' => $categoryId]);
    }

    public function testDeleteJson()
    {
        $genre = factory(Genre::class)->create();
        $this
            ->deleteJson('/api/genres/' . $genre->id)
            ->assertStatus(204);
        $this->assertNull(Genre::find($genre->id));
        $this->assertNotNull(Genre::withTrashed()->find($genre->id));
    }

    public function testDestroy()
    {
        $response = $this->json('DELETE', route('genres.destroy', ['genre' => $this->genre->id]));
        $response->assertStatus(204);
        $this->assertNull(Genre::find($this->genre->id));
        $this->assertNotNull(Genre::withTrashed()->find($this->genre->id));
    }

    protected function routeStore()
    {
        return route('genres.store');
    }

    protected function routeUpdate()
    {
        return route('genres.update', ['genre' => $this->genre->id]);
    }

    protected function model()
    {
        return Genre::class;
    }
}
