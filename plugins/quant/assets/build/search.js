(()=>{"use strict";function t(t){const e=new RegExp("[?&]"+t+"=([^&]*)").exec(window.location.search);return e?decodeURIComponent(e[1].replace(/\+/g," ")):""}function e(t,{html:e,components:a}){const n=Object.keys(t).filter(e=>/^(category|post_tag)_[a-z]{2}$/.test(e)&&Array.isArray(t[e])).flatMap(e=>t[e]);return e`
		<article class="quant-search__hit">
			<a href="${t.url}">
				${t.image?e`<img
							src="${t.image}"
							alt="${i=t.title,String(null!=i?i:"").replace(/[&<>"']/g,t=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[t]))}"
							loading="lazy"
					  />`:""}
				<h3 class="quant-search__hit-title">
					${a.Highlight({hit:t,attribute:"title"})}
				</h3>
			</a>
			<p class="quant-search__hit-summary">
				${a.Snippet({hit:t,attribute:"summary"})}
			</p>
			<div>
				${n.map(t=>e`<span class="quant-search__hit-tag"
							>${t}</span
						>`)}
			</div>
		</article>
	`;var i}function a(a){let n;try{n=JSON.parse(a.getAttribute("data-quant-search"))}catch(t){return}if(!n.index||"undefined"==typeof instantsearch||"undefined"==typeof algoliasearch)return;const i=instantsearch({indexName:n.index.index,searchClient:algoliasearch(n.index.application_id,n.index.read_key),routing:!1,initialUiState:{[n.index.index]:{query:t(n.queryParam||"keys")}}}),s=[instantsearch.widgets.configure({attributesToSnippet:["summary:40"],snippetEllipsisText:"…",filters:n.filters||"",hitsPerPage:n.display&&n.display.per_page||10})],r=(t,e)=>{const n=a.querySelector(t);n&&s.push(e(n))};r("[data-quant-searchbox]",t=>instantsearch.widgets.searchBox({container:t,placeholder:"Search…"})),r("[data-quant-stats]",t=>instantsearch.widgets.stats({container:t,templates:{text:({nbHits:t,hasOneResult:e})=>e?"1 result":`${t.toLocaleString()} results`}})),r("[data-quant-clear]",t=>instantsearch.widgets.clearRefinements({container:t})),r("[data-quant-hits]",t=>instantsearch.widgets.hits({container:t,templates:{empty:()=>'<div class="quant-search__empty"><h4>No results found.</h4><p>Try different search terms or filters.</p></div>',item:e}})),r("[data-quant-pagination]",t=>instantsearch.widgets.pagination({container:t})),(n.facets||[]).forEach(t=>{const e=function(t,e){const a=t.querySelector("#"+e.container);if(!a)return null;const n={container:a,attribute:e.attribute,limit:e.limit||10};switch(e.display){case"menu":return instantsearch.widgets.menu(n);case"select":return instantsearch.widgets.menuSelect(n);default:return instantsearch.widgets.refinementList(n)}}(a,t);e&&s.push(e)}),i.addWidgets(s),i.start()}function n(){document.querySelectorAll("[data-quant-search]").forEach(a)}"loading"===document.readyState?document.addEventListener("DOMContentLoaded",n):n()})();