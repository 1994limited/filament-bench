<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bench shop's tables. The generator (bench/generate.php) fills them with
 * plain inserts, never through the models, so the code under test cannot
 * shape its own fixture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 2)->unique();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('avatar')->nullable();
            $table->string('status');
            $table->integer('balance');
            $table->boolean('is_active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->string('name');
            $table->string('sku')->unique();
            $table->integer('price');
            $table->integer('stock');
            $table->boolean('is_visible');
            $table->string('image')->nullable();
            $table->json('images')->nullable();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->longText('specs')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('number')->unique();
            $table->string('status');
            $table->integer('total');
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            // indexed, so the query builder's date operators have an index to use or to miss
            $table->dateTime('placed_at')->index();
            $table->dateTime('shipped_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('sort');
            $table->integer('qty');
            $table->integer('unit_price');
        });

        // spatie/laravel-tags' schema
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('slug');
            $table->string('type')->nullable();
            $table->integer('order_column')->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }
};
