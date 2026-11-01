<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class FaqCrudTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    public function test_can_create_update_and_delete_faq(): void
    {
        $user = $this->makeAdminUser();

        $create = $this->actingAs($user)->graphQL('
            mutation ($question: String!, $answer: String!, $is_active: Boolean) {
                FaqCreate(question: $question, answer: $answer, is_active: $is_active) {
                    code
                    message
                }
            }
        ', [
            'question' => 'Как оформить заказ?',
            'answer' => "Добавьте товары в корзину.\nОформите заказ на экране корзины.",
            'is_active' => true,
        ]);

        $create->assertJson(['data' => ['FaqCreate' => ['code' => 200]]]);

        $faq = Faq::where('question', 'Как оформить заказ?')->firstOrFail();
        $this->assertSame("Добавьте товары в корзину.\nОформите заказ на экране корзины.", $faq->answer);
        $this->assertNull($faq->position, 'Новый вопрос создаётся без позиции и попадает в конец списка');

        $update = $this->actingAs($user)->graphQL('
            mutation ($id: Int!, $question: String) {
                FaqUpdate(id: $id, question: $question) {
                    code
                    message
                }
            }
        ', [
            'id' => $faq->id,
            'question' => 'Как оформить заказ в приложении?',
        ]);

        $update->assertJson(['data' => ['FaqUpdate' => ['code' => 200]]]);
        $this->assertSame('Как оформить заказ в приложении?', $faq->fresh()->question);

        $delete = $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                FaqDelete(id: $id) {
                    code
                    message
                }
            }
        ', ['id' => $faq->id]);

        $delete->assertJson(['data' => ['FaqDelete' => ['code' => 200]]]);
        $this->assertSoftDeleted('faqs', ['id' => $faq->id]);
    }

    public function test_create_requires_question_and_answer(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation {
                FaqCreate(question: "", answer: "") {
                    code
                    message
                }
            }
        ');

        $response->assertGraphQLErrorMessage('Validation failed for the field [FaqCreate].');
    }

    public function test_create_does_not_accept_position_argument(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->graphQL('
            mutation {
                FaqCreate(question: "Вопрос", answer: "Ответ", position: 5) {
                    code
                }
            }
        ');

        $response->assertJsonStructure(['errors']);
    }

    public function test_all_faqs_returns_inactive_too(): void
    {
        $user = $this->makeAdminUser();

        Faq::create(['question' => 'Активный вопрос', 'answer' => 'Ответ', 'is_active' => true]);
        Faq::create(['question' => 'Скрытый вопрос', 'answer' => 'Ответ', 'is_active' => false]);

        $all = $this->actingAs($user)->graphQL('query { allFaqs { id question is_active } }');
        $this->assertCount(2, $all->json('data.allFaqs'));

        $active = $this->actingAs($user)->graphQL('query { faqs { id question } }');
        $this->assertCount(1, $active->json('data.faqs'));
    }
}
