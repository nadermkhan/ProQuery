# ProQuery - Lightweight Single-File SQLite ORM for PHP

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![SQLite](https://img.shields.io/badge/SQLite-3-blue.svg)](https://www.sqlite.org)
[![Size](https://img.shields.io/badge/Size-~100KB-brightgreen.svg)](ProQuery.php)
[![Zero Dependencies](https://img.shields.io/badge/Dependencies-Zero-orange.svg)](ProQuery.php)

> 🚀 **Enterprise-grade ORM features in a single PHP file. Zero dependencies. Maximum power.**

ProQuery is a comprehensive SQLite ORM that delivers Laravel Eloquent-style functionality without the framework overhead. Perfect for projects that need powerful database abstraction without complex dependency management.

## ✨ Features

### 🎯 **Core Features**
- 📦 **Single-file distribution** - Just drop and use
- 🔥 **Zero dependencies** - Pure PHP, no Composer required
- 🎨 **Eloquent-style syntax** - Familiar and intuitive API
- ⚡ **Optimized for SQLite** - WAL mode, memory temp storage, smart caching
- 🛡️ **Production-ready** - Battle-tested patterns and error handling

### 🔗 **Relationships & ORM**
- ✅ **Full relationship support** - HasOne, HasMany, BelongsTo, BelongsToMany
- ✅ **Advanced relationships** - HasManyThrough, HasOneThrough
- ✅ **Polymorphic relations** - MorphTo, MorphOne, MorphMany, MorphToMany
- ✅ **Eager loading** - Prevent N+1 queries with `with()` method
- ✅ **Lazy loading** - Load relationships on-demand

### 🛠️ **Database Features**
- 📊 **Schema builder** - Fluent table creation and modification
- 🔄 **Migration system** - Version control for your database
- 🌱 **Database seeding** - Populate your database with test data
- 📄 **Query builder** - Powerful, chainable query construction
- 📈 **Aggregates** - COUNT, SUM, AVG, MIN, MAX support
- 📑 **Pagination** - Built-in pagination with helpers

### 🚀 **Performance & Debugging**
- 💾 **Query caching** - Cache frequently used queries
- 📝 **Query logging** - Track and analyze all queries
- 🔍 **Debug helpers** - `dd()` and `dump()` methods
- 🎯 **Chunk processing** - Handle large datasets efficiently
- ⚡ **Optimized eager loading** - Smart batching of related queries

## 📥 Installation

### Option 1: Direct Download
```bash
wget https://raw.githubusercontent.com/nadermkhan/ProQuery/main/ProQuery.php
```

### Option 2: Git Clone
```bash
git clone https://github.com/nadermkhan/ProQuery.git
```

### Option 3: Composer (Optional)
```bash
composer require nadermkhan/proquery
```

## 🚀 Quick Start

```php
<?php
require_once 'ProQuery.php';

// Initialize database
ProQuery::init('database.db');

// Define your model
class User extends Model {
    protected static $table = 'users';
    protected static $fillable = ['name', 'email', 'password'];
    
    public function posts() {
        return $this->hasMany(Post::class);
    }
}

// Create the table
Schema::create('users', function(Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->timestamps();
});

// Create a user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT)
]);

// Query users
$users = User::where('created_at', '>', '2024-01-01')
             ->orderBy('name')
             ->limit(10)
             ->get();

// Eager load relationships
$users = User::with('posts')->get();
```

## 📚 Documentation

### Table of Contents
- [Configuration](#configuration)
- [Models](#models)
- [Query Builder](#query-builder)
- [Relationships](#relationships)
- [Schema Builder](#schema-builder)
- [Migrations](#migrations)
- [Advanced Features](#advanced-features)

### Configuration

Initialize ProQuery with your database:

```php
// File-based database
ProQuery::init('path/to/database.db');

// In-memory database (great for testing)
ProQuery::init(':memory:');

// Enable query logging
ProQuery::getInstance()->enableQueryLog();
```

### Models

Define models by extending the base Model class:

```php
class Post extends Model {
    // Table name (optional, defaults to lowercase plural of class name)
    protected static $table = 'posts';
    
    // Mass assignable attributes
    protected static $fillable = ['title', 'content', 'user_id'];
    
    // Hidden attributes (excluded from JSON/array)
    protected static $hidden = ['internal_notes'];
    
    // Attribute casting
    protected static $casts = [
        'published' => 'boolean',
        'metadata' => 'json',
        'published_at' => 'datetime'
    ];
    
    // Enable/disable timestamps
    protected static $timestamps = true;
}
```

### Query Builder

#### Basic Queries
```php
// Retrieve all records
$users = User::all();

// Find by primary key
$user = User::find(1);
$user = User::findOrFail(1); // Throws exception if not found

// Where clauses
$users = User::where('status', 'active')->get();
$users = User::where('age', '>', 18)->get();
$users = User::whereIn('role', ['admin', 'moderator'])->get();
$users = User::whereBetween('age', [18, 65])->get();
$users = User::whereNull('deleted_at')->get();

// Ordering
$users = User::orderBy('name', 'ASC')->get();
$users = User::latest()->get(); // Order by created_at DESC
$users = User::oldest()->get(); // Order by created_at ASC

// Limiting
$users = User::limit(10)->offset(20)->get();
$users = User::take(5)->skip(10)->get();
```

#### Advanced Queries
```php
// Aggregates
$count = User::count();
$sum = Order::sum('total');
$avg = Product::avg('price');
$min = Product::min('price');
$max = Product::max('price');

// Exists checks
if (User::where('email', 'test@example.com')->exists()) {
    // User exists
}

// Joins
$users = User::query()
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->select('users.*', 'posts.title')
    ->get();

// Raw expressions
$users = User::query()
    ->select(raw('COUNT(*) as post_count'))
    ->groupBy('status')
    ->get();
```

### Relationships

#### Defining Relationships
```php
class User extends Model {
    // One-to-One
    public function profile() {
        return $this->hasOne(Profile::class);
    }
    
    // One-to-Many
    public function posts() {
        return $this->hasMany(Post::class);
    }
    
    // Belongs To
    public function company() {
        return $this->belongsTo(Company::class);
    }
    
    // Many-to-Many
    public function roles() {
        return $this->belongsToMany(Role::class, 'user_roles')
                    ->withPivot(['expires_at'])
                    ->withTimestamps();
    }
    
    // Has Many Through
    public function comments() {
        return $this->hasManyThrough(Comment::class, Post::class);
    }
    
    // Polymorphic Relations
    public function images() {
        return $this->morphMany(Image::class, 'imageable');
    }
}
```

#### Using Relationships
```php
// Eager loading (prevents N+1 queries)
$users = User::with(['posts', 'roles'])->get();
$users = User::with('posts.comments')->get(); // Nested

// Lazy loading
$user = User::find(1);
$posts = $user->posts; // Loads on access

// Relationship operations
$user->roles()->attach($roleId);
$user->roles()->detach($roleId);
$user->roles()->sync([1, 2, 3]);
$user->posts()->create(['title' => 'New Post']);
```

### Schema Builder

```php
// Create table
Schema::create('products', function(Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->integer('stock')->default(0);
    $table->boolean('active')->default(true);
    $table->json('metadata')->nullable();
    $table->timestamps();
    
    $table->index('name');
    $table->unique('sku');
});

// Modify table
Schema::table('products', function(Blueprint $table) {
    $table->string('sku', 50)->unique();
    $table->foreign('category_id')->references('id')->on('categories');
});

// Drop table
Schema::dropIfExists('products');

// Check existence
if (Schema::hasTable('products')) {
    // Table exists
}
```

### Migrations

```php
// Create a migration
class CreateProductsTable extends Migration {
    public function up() {
        Schema::create('products', function(Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }
    
    public function down() {
        Schema::drop('products');
    }
}

// Run migrations
$migrator = new Migrator('./migrations');
$migrator->run();

// Rollback
$migrator->rollback();
$migrator->rollback(3); // Rollback 3 batches

// Reset and refresh
$migrator->reset();
$migrator->refresh();
```

### Advanced Features

#### Pagination
```php
$users = User::paginate(15); // 15 per page

// Access pagination data
$users->items();        // Current page items
$users->total();        // Total items
$users->currentPage();  // Current page number
$users->lastPage();     // Last page number
$users->hasMorePages(); // Check if more pages exist

// Render links
echo $users->links();
```

#### Chunking
```php
// Process large datasets efficiently
User::chunk(100, function($users) {
    foreach ($users as $user) {
        // Process user
    }
});
```

#### Debugging
```php
// Dump and die
User::where('status', 'active')->dd();

// Dump and continue
User::where('status', 'active')->dump()->get();

// Get SQL
$sql = User::where('status', 'active')->toSql();

// Query log
$queries = ProQuery::getInstance()->getQueryLog();
```

## 🏗️ Real-World Example

```php
<?php
require_once 'ProQuery.php';

// Initialize
ProQuery::init('blog.db');

// Models
class User extends Model {
    protected static $fillable = ['name', 'email', 'password'];
    
    public function posts() {
        return $this->hasMany(Post::class);
    }
    
    public function comments() {
        return $this->hasMany(Comment::class);
    }
}

class Post extends Model {
    protected static $fillable = ['title', 'content', 'user_id', 'published'];
    protected static $casts = ['published' => 'boolean'];
    
    public function user() {
        return $this->belongsTo(User::class);
    }
    
    public function comments() {
        return $this->hasMany(Comment::class);
    }
    
    public function tags() {
        return $this->belongsToMany(Tag::class);
    }
}

// Create schema
Schema::create('users', function($table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->timestamps();
});

Schema::create('posts', function($table) {
    $table->id();
    $table->string('title');
    $table->text('content');
    $table->integer('user_id');
    $table->boolean('published')->default(false);
    $table->timestamps();
    $table->foreign('user_id')->references('id')->on('users');
});

// Usage
$user = User::create([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT)
]);

$post = $user->posts()->create([
    'title' => 'Getting Started with ProQuery',
    'content' => 'ProQuery makes database operations simple...',
    'published' => true
]);

// Complex query
$popularPosts = Post::with(['user', 'comments'])
    ->where('published', true)
    ->where('created_at', '>', date('Y-m-d', strtotime('-7 days')))
    ->orderBy('views', 'DESC')
    ->limit(10)
    ->get();
```

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Inspired by [Laravel Eloquent](https://laravel.com/docs/eloquent)
- Built for the PHP community that values simplicity and power

## 💬 Support

- 📧 Email: nadermkhan@gmail.com
- 🐛 Issues: [GitHub Issues](https://github.com/nadermkhan/ProQuery/issues)
- 💡 Discussions: [GitHub Discussions](https://github.com/nadermkhan/ProQuery/discussions)

## 🌟 Star History

[![Star History Chart](https://api.star-history.com/svg?repos=nadermkhan/ProQuery&type=Date)](https://star-history.com/#nadermkhan/ProQuery&Date)

---

**Built with ❤️ by [Nader Mahbub Khan](https://github.com/nadermkhan)**

*If you find ProQuery useful, please ⭐ star this repository!*
```

This README provides a comprehensive overview of ProQuery with clear examples, making it easy for developers to understand and start using the framework immediately. The documentation is structured, professional, and highlights all the powerful features while maintaining the simplicity that makes ProQuery special.
