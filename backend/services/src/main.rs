/// Use axum capabities.
use axum::routing::{get, post};
use serde_json::{json, Value};
#[tokio::main]
async fn main() {
    // Build our application by creating our router.
    let app = axum::Router::new()
        .route("/",
        get(home)
        )
        .route("/api/get_article_list_and_view",
            // get(get_books)
            post(article_list_and_view)
        );

    println!("Run our application as a hyper server on http://localhost:3000.");
    axum::Server::bind(&"0.0.0.0:3000".parse().unwrap())
        .serve(app.into_make_service())
        .await
        .unwrap();
}

/// See file book.rs, which defines the `Book` struct.
mod book;
use crate::book::Book;

/// See file data.rs, which defines the DATA global variable.
mod data;
use crate::data::DATA;

/// Use Thread for spawning a thread e.g. to acquire our crate::DATA mutex lock.
use std::thread;

/// To access data, create a thread, spawn it, then get the lock.
/// When you're done, then join the thread with its parent thread.
#[allow(dead_code)]
async fn print_data() {
    thread::spawn(move || {
        let data = DATA.lock().unwrap();
        println!("data: {:?}" ,data);
    }).join().unwrap()
}

/// axum handler for "GET /books" which responds with a resource page.
/// This demo uses our DATA; a production app could use a database.
/// This demo must clone the DATA in order to sort items by title.
pub async fn home() -> axum::response::Html<String> {
    thread::spawn(move || {
        let data = DATA.lock().unwrap();
        let mut books = data.values().collect::<Vec<_>>().clone();
        books.sort_by(|a, b| a.title.cmp(&b.title));
        books.iter().map(|&book|
            format!("<p>Your index.html goes here</p><p>{}</p>\n", &book)
        ).collect::<String>()
    }).join().unwrap().into()
}


pub async fn article_list_and_view(
    axum::extract::Json(input): axum::extract::Json<serde_json::Value>
) -> axum::extract::Json<Value> {
    thread::spawn(move || {
        // let name = input.get("name");
        json!(
[
    {
        "article_id": 1,
        "article_title": "A history of fashion",
        "author_name": "Violet Lee",
        "author_pfp": "www.violetleepfp.com",
        "date_posted": "12/25/2021 15:19:00",
        "total_invested": 432,
        "image_url": "http://backgroundImage",
        "tags": "for you, fashion"
    },
    {
        "article_id": 2,
        "article_title": "A history of fashion",
        "author_name": "Violet Lee",
        "author_pfp": "www.violetleepfp.com",
        "date_posted": "12/25/2021 15:19:00",
        "total_invested": 432,
        "image_url": "http://backgroundImage",
        "tags": "for you, fashion"
    },
    {
        "article_id": 3,
        "article_title": "A history of fashion",
        "author_name": "Violet Lee",
        "author_pfp": "www.violetleepfp.com",
        "date_posted": "12/25/2021 15:19:00",
        "total_invested": 432,
        "image_url": "http://backgroundImage",
        "tags": "for you, fashion"
    },
    {
        "article_id": 4,
        "article_title": "A history of fashion",
        "author_name": "Violet Lee",
        "author_pfp": "www.violetleepfp.com",
        "date_posted": "12/25/2021 15:19:00",
        "total_invested": 432,
        "image_url": "http://backgroundImage",
        "tags": "for you, fashion"
    },
    {
        "article_id": 5,
        "article_title": "A history of fashion",
        "author_name": "Violet Lee",
        "author_pfp": "www.violetleepfp.com",
        "date_posted": "12/25/2021 15:19:00",
        "total_invested": 432,
        "image_url": "http://backgroundImage",
        "tags": "for you, fashion"
    }
]
    )
    }).join().unwrap().into()
}

