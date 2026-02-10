# AIKnowledgeCMS

AIKnowledgeCMS is a self-growing knowledge-oriented CMS that automatically collects, filters, and organizes domain-specific information into a long-term knowledge base.

Rather than publishing content, it is designed to **accumulate understanding** over time.

---

## Why AIKnowledgeCMS Exists

Most CMS platforms are optimized for:

- Publishing frequency
- SEO and visibility
- Manual content updates

AIKnowledgeCMS takes a different stance.

- Learning happens continuously, even without posting
- Knowledge should compound, not reset daily
- A system should stay alive without constant human input

This project exists to support **quiet, consistent knowledge accumulation** driven by genuine interests, not engagement metrics.

---

## What AIKnowledgeCMS Does

AIKnowledgeCMS operates as an autonomous knowledge loop:

- Collects information from predefined, trusted sources
- Filters and analyzes content using AI-assisted logic
- Stores results as structured, date-based knowledge JSON
- Accumulates insight incrementally, day by day

The system grows naturally alongside your thinking, research, or observation process.

---

## How It Works (Conceptual Flow)

1. External information is collected per topic or keyword  
2. Each item is analyzed and stored as structured knowledge  
3. Daily knowledge is optionally summarized  
4. Knowledge remains accessible, searchable, and reusable  

Nothing is overwritten.  
Nothing is optimized for trends.  
Everything compounds.

---

## Core Components

AIKnowledgeCMS is intentionally split into small, role-specific components.

- `aiknowledgecms.php`  
  Handles CMS-level responsibilities such as:
  - Data storage
  - Daily views
  - Keyword navigation
  - Manual edits when needed

- `aiknowledgecms.py`  
  Performs AI-assisted processing, including:
  - Content analysis
  - Knowledge extraction
  - Structured JSON generation

- `news2audio.py`  
  Converts accumulated knowledge into audio-ready scripts, enabling optional downstream use such as narration or radio-style output.

This separation keeps knowledge accumulation stable while allowing media-related extensions to evolve independently.

---

## What AIKnowledgeCMS Is NOT

- Not a news aggregator
- Not an SEO-focused content generator
- Not a social media automation tool
- Not designed for virality or volume

AIKnowledgeCMS values **relevance, continuity, and clarity** over reach.

---

## Intended Use Cases

AIKnowledgeCMS is suited for:

- Personal knowledge bases
- Long-term research tracking
- Domain-focused learning archives
- Small teams sharing accumulated understanding

It works best where **thinking long-term matters more than posting often**.

---

## Project Philosophy

AIKnowledgeCMS follows a simple order:

1. Learn for yourself  
2. Organize what matters  
3. Let knowledge accumulate naturally  

Any outward expression (articles, audio, summaries, media) should be a **byproduct**, not the objective.

---

## Relationship to Other Projects

AIKnowledgeCMS can function independently, but is designed to integrate cleanly with external systems such as:

- AIRadio
- Script-based audio or video generation pipelines
- Other AI-driven media layers

By separating knowledge storage from media execution, the system remains reusable, extensible, and resilient.

---

## Status

This project is actively used in a real production environment and continues to evolve.

Current development focuses on:
- Stability of daily knowledge accumulation
- Clear separation between knowledge and media layers

Media-related features remain modular and optional by design.

---

## License

MIT License

